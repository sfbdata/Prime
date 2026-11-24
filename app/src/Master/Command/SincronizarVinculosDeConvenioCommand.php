<?php

declare(strict_types=1);

namespace App\Master\Command;

use App\Master\UseCase\SincronizarVinculosDeConvenioUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A volta diária dos vínculos de convênio (spec docs/specs/convenios.md, §5.1, PR 3). Agendada todo dia às 2h10
 * ({@see \App\Shared\Scheduler\AgendaDeTarefas}). Idempotente: rodar de novo à mão não duplica nada.
 */
#[AsCommand(
    name: 'app:convenios:sincronizar-vinculos',
    description: 'Cria, confere e encerra os vínculos dos escritórios com os convênios (Convênio OAB pela UF e pela OAB do criador).',
)]
final class SincronizarVinculosDeConvenioCommand extends Command
{
    public function __construct(private readonly SincronizarVinculosDeConvenioUseCase $useCase)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $r = $this->useCase->executar();
        $output->writeln(sprintf(
            'escritorios: %d · criados: %d · encerrados: %d · provas pendentes: %d · provas de volta: %d',
            $r->escritorios,
            $r->criados,
            $r->encerrados,
            $r->provasPendentes,
            $r->provasDeVolta,
        ));

        return Command::SUCCESS;
    }
}
