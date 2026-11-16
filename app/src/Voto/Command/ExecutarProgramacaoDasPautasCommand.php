<?php

declare(strict_types=1);

namespace App\Voto\Command;

use App\Voto\UseCase\ExecutarProgramacaoDasPautasUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `voto:executar-programacao`: abre e encerra as pautas do Voto que têm horário programado. Roda a cada minuto pela
 * {@see \App\Shared\Scheduler\AgendaDeTarefas}. Nada a fazer termina com sucesso, sem saída.
 */
#[AsCommand(name: 'voto:executar-programacao', description: 'Abre e encerra as pautas do Voto programadas para o horário')]
final class ExecutarProgramacaoDasPautasCommand extends Command
{
    public function __construct(private readonly ExecutarProgramacaoDasPautasUseCase $programacao)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resumo = $this->programacao->executar();
        if (0 !== $resumo['abertas'] || 0 !== $resumo['encerradas']) {
            $output->writeln(sprintf('Pautas abertas: %d. Pautas encerradas: %d.', $resumo['abertas'], $resumo['encerradas']));
        }

        return Command::SUCCESS;
    }
}
