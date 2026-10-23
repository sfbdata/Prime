<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Repository\VarreduraDePushRepository;
use App\Agenda\Repository\VarreduraDoGoogleRepository;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\MessageHandler\ExpandirCampanhasHandler;
use App\Comercial\MessageHandler\VarrerAutomacoesHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * As quatro varreduras periódicas que antes se reagendavam sozinhas com `DelayStamp` (spec
 * `docs/specs/scheduler-tarefas-agendadas.md`, "Cadeias no Scheduler"): push dos lembretes, Google Agenda,
 * automações do Comercial e expansor de campanhas.
 *
 * Schedule próprio (`cadeias`, transport `scheduler_cadeias`), consumido pelo **worker** (`jusprime_worker_prod`),
 * que sobe em todo deploy; o contêiner `scheduler` pode ficar desligado pela trava do crontab e as varreduras não
 * podem parar por isso. Cada volta é um {@see RedispatchMessage}: o gerador só põe a mensagem no transport de hoje
 * (`async` ou `comercial`), e o trabalho continua com a nova tentativa e a fila de falhas de sempre.
 *
 * - Sem `stateful`: uma volta que passou da hora durante uma parada não roda ao voltar (a próxima vem no
 *   intervalo seguinte).
 * - `processOnlyLastMissedRun`: com o worker ocupado (um envio longo ao Drive), as voltas perdidas viram uma só
 *   por varredura, em vez de uma fila delas.
 * - `lock`: trava própria (`agendador-de-tarefas-cadeias`, mesma loja da do schedule `default`), para dois
 *   workers nunca gerarem a mesma volta. Vida curta ({@see self::VIDA_DA_TRAVA}): a imagem não tem `pcntl`, o
 *   `docker stop` mata o worker sem soltar a trava, e o worker novo espera ela expirar.
 *
 * Segunda proteção: cada handler reivindica a vez numa linha do banco (janela de cerca de metade do intervalo),
 * então uma volta a mais (virada, dois workers) não faz o trabalho em dobro.
 *
 * A lista é um contrato: `AgendaDasCadeiasTest` confere mensagem, intervalo e transport de cada uma.
 */
#[AsSchedule(self::NOME)]
final class AgendaDasCadeias implements ScheduleProviderInterface
{
    public const NOME = 'cadeias';

    /** Nome da trava na tabela lock_keys (um por schedule; o `default` usa {@see AgendaDeTarefas::TRAVA}). */
    public const TRAVA = 'agendador-de-tarefas-cadeias';

    /**
     * Segundos de vida da trava. O Checkpoint a renova a cada volta até a próxima mais esta folga: um worker morto
     * sem soltar a trava segura as varreduras por no máximo um intervalo do push mais 60 s (cerca de 2 min).
     */
    public const VIDA_DA_TRAVA = 60;

    private ?Schedule $schedule = null;

    public function __construct(
        #[Autowire(service: 'lock.agendador.factory')]
        private readonly LockFactory $travas,
    ) {
    }

    /**
     * Mensagem => intervalo em segundos e transport de destino (o mesmo de antes da troca). Os intervalos moram ao
     * lado de cada trava no banco, que usa a metade deles como janela.
     *
     * @return array<class-string, array{segundos: int, transport: string}>
     */
    public static function cadeias(): array
    {
        return [
            VarrerLembretesDePush::class => ['segundos' => VarreduraDePushRepository::INTERVALO_SEGUNDOS, 'transport' => 'async'],
            VarrerGoogleAgenda::class    => ['segundos' => VarreduraDoGoogleRepository::INTERVALO_SEGUNDOS, 'transport' => 'async'],
            VarrerAutomacoes::class      => ['segundos' => VarrerAutomacoesHandler::INTERVALO_SEGUNDOS, 'transport' => 'comercial'],
            ExpandirCampanhas::class     => ['segundos' => ExpandirCampanhasHandler::INTERVALO_SEGUNDOS, 'transport' => 'comercial'],
        ];
    }

    public function getSchedule(): Schedule
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }

        $mensagens = [];
        foreach (self::cadeias() as $classe => $cadeia) {
            $mensagens[] = RecurringMessage::every($cadeia['segundos'], new RedispatchMessage(new $classe(), $cadeia['transport']));
        }

        return $this->schedule = (new Schedule())
            ->add(...$mensagens)
            ->processOnlyLastMissedRun(true)
            ->lock($this->travas->createLock(self::TRAVA, self::VIDA_DA_TRAVA));
    }
}
