<?php

declare(strict_types=1);

namespace App\Backup\Command;

use App\Backup\UseCase\VencerBackupsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `app:backup:vencer`: vence os backups prontos fora da validade (apaga o ZIP) e marca como falhou os presos em
 * preparo. De hora em hora pela {@see \App\Shared\Scheduler\AgendaDeTarefas}; sem nada a fazer, termina em silêncio.
 */
#[AsCommand(name: 'app:backup:vencer', description: 'Vence os backups prontos fora da validade e marca como falhou os presos em preparo')]
final class VencerBackupsCommand extends Command
{
    public function __construct(private readonly VencerBackupsUseCase $vencer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resumo = $this->vencer->executar();
        if ($resumo['vencidos'] > 0 || $resumo['falhos'] > 0) {
            $output->writeln(sprintf('Backups vencidos: %d. Presos em preparo marcados como falhou: %d.', $resumo['vencidos'], $resumo['falhos']));
        }

        return Command::SUCCESS;
    }
}
