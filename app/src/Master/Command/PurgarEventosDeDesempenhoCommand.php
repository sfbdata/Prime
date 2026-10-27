<?php

declare(strict_types=1);

namespace App\Master\Command;

use App\Master\UseCase\PurgarEventosDeDesempenhoUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retenção do monitor de saúde do Master: apaga os eventos de desempenho com mais de 90 dias (LGPD, spec
 * docs/specs/master-desempenho.md §2.1). O agendamento diário entra no PR 5.
 */
#[AsCommand(
    name: 'app:desempenho:purgar',
    description: 'Apaga os eventos do monitor de desempenho com mais de 90 dias.',
)]
final class PurgarEventosDeDesempenhoCommand extends Command
{
    public function __construct(private readonly PurgarEventosDeDesempenhoUseCase $useCase)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d evento(s) de desempenho apagado(s).', $this->useCase->executar()));

        return Command::SUCCESS;
    }
}
