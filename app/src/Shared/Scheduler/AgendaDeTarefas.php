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
     * Só o que já rodava no crontab real da VPS (conferido em 23/10/2026). Resumo diário, cobrança do
     * checklist e índices monetários nunca rodaram em produção: ficam desligados até o dono decidir
     * (spec, "Desligadas, aguardando o dono").
     */
    private const TAREFAS = [
        // 0h: purga de cadastros expirados e de escritórios em quarentena vencida. Depois do
        // backup das 23h e do backup do banco das 23h30 (os dois seguem no crontab do host).
        'app:purgar-dados-expirados' => [
            'comando' => 'app:purgar-dados-expirados --force --no-interaction',
            'cron'    => '0 0 * * *',
        ],
        // 3h: captação das comunicações do DJEN dos últimos 2 dias (como no crontab real, 06h UTC).
        'app:djen:sincronizar' => [
            'comando' => 'app:djen:sincronizar --dias=2 --no-interaction',
            'cron'    => '0 3 * * *',
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
        // De hora em hora: envio do sistema para o Drive (só sistema para Drive; importar nunca
        // é automático). Tenant e usuário são os do script antigo sync-reconciliar.sh.
        'app:sync:reconciliar' => [
            'comando' => 'app:sync:reconciliar --tenant-id=1 --usuario-id=1 --modo=enviar --no-interaction',
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
