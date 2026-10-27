<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * As tarefas agendadas de produção, que antes moravam no crontab do host da VPS
 * (spec `docs/specs/scheduler-tarefas-agendadas.md`). Quem as executa é o contêiner `scheduler`
 * (`messenger:consume scheduler_default`): cada tarefa vira um {@see RunCommandMessage}, que roda o
 * comando no próprio processo do consumidor.
 *
 * Os horários estão no fuso de Brasília ({@see self::FUSO}), não em UTC como no crontab antigo: o
 * gatilho cron recebe o fuso, então "9h" é 9h de Brasília qualquer que seja o relógio da máquina.
 *
 * - `stateful`: a última execução de cada tarefa fica no banco (pool `cache.agendador`). Depois de
 *   uma parada (o deploy recria o contêiner), a execução que passou da hora roda ao voltar, só a
 *   última de cada tarefa (`processOnlyLastMissedRun`), em vez de se perder.
 * - `lock`: a trava (tabela `lock_keys`) garante que dois consumidores nunca gerem a mesma tarefa.
 *
 * Falha (código de saída diferente de 0 ou exceção): {@see AvisoDeFalhaDoAgendador}.
 *
 * A lista é um contrato: `AgendaDeTarefasTest` confere comando, expressão e fuso de cada tarefa.
 */
#[AsSchedule]
final class AgendaDeTarefas implements ScheduleProviderInterface
{
    public const FUSO = 'America/Sao_Paulo';

    /** Nome da trava na tabela lock_keys (um por schedule). */
    public const TRAVA = 'agendador-de-tarefas-default';

    /**
     * As tarefas, na ordem em que rodam quando duas caem no mesmo minuto (o consumidor é um só e
     * as executa em sequência). A sincronização com o Drive, de hora em hora, vai por último: é a
     * mais demorada e não pode atrasar as diárias. Chave = nome do comando.
     *
     * O que já rodava no crontab real da VPS (conferido em 23/10/2026), mais a foto do Dashboard e os
     * índices monetários (ligados por decisão do dono). Resumo diário e cobrança do checklist nunca
     * rodaram em produção: ficam desligados até o dono decidir (spec, "Desligadas, aguardando o dono").
     */
    private const TAREFAS = [
        // 0h: purga de cadastros expirados e de escritórios em quarentena vencida. Depois do
        // backup das 23h e do backup do banco das 23h30 (os dois seguem no crontab do host).
        'app:purgar-dados-expirados' => [
            'comando' => 'app:purgar-dados-expirados --force --no-interaction',
            'cron'    => '0 0 * * *',
        ],
        // 0h20: retenção da BlueJus IA. O conteúdo das análises com mais de 12 meses é apagado (decisão de
        // 24/10/2026, docs/specs/ia-anthropic-ligar.md §10, Q5); ficam modelo, tokens e datas.
        'app:inteligencia:expirar-conteudo' => [
            'comando' => 'app:inteligencia:expirar-conteudo --force --no-interaction',
            'cron'    => '20 0 * * *',
        ],
        // 0h40: purga dos eventos de desempenho com mais de 90 dias (LGPD, docs/specs/master-desempenho.md §2.1).
        'app:desempenho:purgar' => [
            'comando' => 'app:desempenho:purgar --no-interaction',
            'cron'    => '40 0 * * *',
        ],
        // Dia 11, 1h: importação dos índices do BCB (INPC, IPCA e taxa legal), como no DEPLOY.md
        // (04h UTC). Ligada por decisão do dono em 23/10/2026.
        'app:importar-indices-monetarios' => [
            'comando' => 'app:importar-indices-monetarios --no-interaction',
            'cron'    => '0 1 11 * *',
        ],
        // 3h: captação das comunicações do DJEN dos últimos 2 dias (como no crontab real, 06h UTC).
        'app:djen:sincronizar' => [
            'comando' => 'app:djen:sincronizar --dias=2 --no-interaction',
            'cron'    => '0 3 * * *',
        ],
        // Espelho R2 (P2.5 e P2.7, docs/specs/p2-gravacao-dupla.md): os três rodam com --se-ligado e, com
        // ARMAZENAMENTO_ESPELHO_CATEGORIAS vazia ou sem ESPELHO_R2_*, terminam com sucesso sem fazer nada.
        // 3h30: reconciliação diária. Presença e tamanho de tudo; SHA-256 só da fatia do dia da semana
        // (1/7 das referências, fatia estável), então a semana cobre tudo e nenhuma noite lê o acervo
        // inteiro. Falha, e avisa, quando há ausente, tamanho ou SHA divergente, erro de consulta ao R2
        // ou envio em "falhou" na outbox.
        'armazenamento:reconciliar-espelho' => [
            'comando' => 'armazenamento:reconciliar-espelho --se-ligado --fatia=auto --no-interaction',
            'cron'    => '30 3 * * *',
        ],
        // 4h30: retenção de 90 dias da outbox (LGPD, I4). Vale mesmo com a cópia desligada.
        'armazenamento:limpar-replicacao' => [
            'comando' => 'armazenamento:limpar-replicacao --se-ligado --no-interaction',
            'cron'    => '30 4 * * *',
        ],
        // Segunda, 8h: resumo semanal ao cliente.
        'app:push:resumo-semanal-cliente' => [
            'comando' => 'app:push:resumo-semanal-cliente --no-interaction',
            'cron'    => '0 8 * * 1',
        ],
        // Todo dia, 10h05 e 10h15: os relatórios mensais (o comando só envia na primeira
        // segunda-feira do mês, uma vez por escritório).
        'app:pastas:relatorio-sem-processo' => [
            'comando' => 'app:pastas:relatorio-sem-processo --no-interaction',
            'cron'    => '5 10 * * *',
        ],
        'app:pastas:relatorio-pastas-paradas' => [
            'comando' => 'app:pastas:relatorio-pastas-paradas --no-interaction',
            'cron'    => '15 10 * * *',
        ],
        // 23h55: foto do estoque do Dashboard. O comando fotografa "hoje" no fuso do PHP
        // (Brasília), então roda no fim do dia, para a foto ter o dia inteiro.
        'app:dashboard:fotografar' => [
            'comando' => 'app:dashboard:fotografar --no-interaction',
            'cron'    => '55 23 * * *',
        ],
        // Aos 15 minutos de cada hora: conciliação das assinaturas de espaço do Contribua (cartão). Reprocessa
        // avisos de gateway pendentes, reconsulta as assinaturas que ainda podem mudar e devolve ao Free quem
        // passou do fim do mês pago. Rede de segurança do webhook; idempotente. Sai com erro só quando nenhuma
        // reconsulta deu certo (credencial errada ou gateway fora do ar).
        'app:contribua:conciliar-assinaturas' => [
            'comando' => 'app:contribua:conciliar-assinaturas --no-interaction',
            'cron'    => '15 * * * *',
        ],
        // A cada minuto: o worker do espelho leva ao R2 até 50 envios pendentes e sai. O consumidor é um só
        // e roda uma tarefa por vez, então duas rodadas nunca correm juntas; uma rodada que passa do
        // minuto não acumula atrasadas (processOnlyLastMissedRun). Antes do Drive, que é o mais demorado.
        'armazenamento:replicar-espelho' => [
            'comando' => 'armazenamento:replicar-espelho --se-ligado --limite=50 --no-interaction',
            'cron'    => '* * * * *',
        ],
        // De hora em hora: envio do sistema para o Drive (só sistema para Drive; importar nunca
        // é automático). Sem --tenant-id: percorre todos os escritórios com Drive conectado; o
        // escritório que desconecta para de enviar (decisão do dono, 24/10/2026). Sem nenhum
        // conectado, termina com sucesso. O --usuario-id é obrigatório no comando (só o modo
        // importar o usa).
        'app:sync:reconciliar' => [
            'comando' => 'app:sync:reconciliar --usuario-id=1 --modo=enviar --no-interaction',
            'cron'    => '0 * * * *',
        ],
    ];

    private ?Schedule $schedule = null;

    public function __construct(
        #[Autowire(service: 'cache.agendador')]
        private readonly CacheInterface $estado,
        #[Autowire(service: 'lock.agendador.factory')]
        private readonly LockFactory $travas,
    ) {
    }

    /** @return array<string, array{comando: string, cron: string}> chave = nome do comando */
    public static function tarefas(): array
    {
        return self::TAREFAS;
    }

    public function getSchedule(): Schedule
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }

        $fuso      = new \DateTimeZone(self::FUSO);
        $mensagens = [];
        foreach (self::tarefas() as $tarefa) {
            $mensagens[] = RecurringMessage::cron($tarefa['cron'], new RunCommandMessage($tarefa['comando']), $fuso);
        }

        return $this->schedule = (new Schedule())
            ->add(...$mensagens)
            ->stateful($this->estado)
            ->processOnlyLastMissedRun(true)
            ->lock($this->travas->createLock(self::TRAVA));
    }
}
