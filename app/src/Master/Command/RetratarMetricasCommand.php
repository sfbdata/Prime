<?php

declare(strict_types=1);

namespace App\Master\Command;

use App\Master\UseCase\RetratarMetricasDoDiaUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A foto diária das métricas do gráfico Crescimento do Painel do Master (visão "Diário"). Agendada todo dia
 * às 23h50 ({@see \App\Shared\Scheduler\AgendaDeTarefas}), para a foto ter o dia inteiro.
 */
#[AsCommand(
    name: 'app:master:retratar-metricas',
    description: 'Grava a foto do dia das métricas do gráfico Crescimento (contas, usuários, pastas e bytes).',
)]
final class RetratarMetricasCommand extends Command
{
    public function __construct(private readonly RetratarMetricasDoDiaUseCase $useCase)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->useCase->executar() as $metrica => $valor) {
            $output->writeln(sprintf('%s: %d', $metrica, $valor));
        }

        return Command::SUCCESS;
    }
}
