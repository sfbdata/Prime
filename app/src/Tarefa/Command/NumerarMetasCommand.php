<?php

declare(strict_types=1);

namespace App\Tarefa\Command;

use App\Tarefa\UseCase\NumerarMetasAntigasUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill do número da meta por escritório ("Nº 0001"): as metas criadas antes da numeração nascem com
 * número nulo e só o ganham aqui. Rodar uma vez depois da publicação (DEPLOY.md); rodar de novo não muda
 * nada. A tela aguenta o número nulo enquanto isso (sem número, sem selo).
 */
#[AsCommand(name: 'app:tarefa:numerar-metas', description: 'Dá número, por escritório e em ordem de criação, às metas que ainda não têm')]
final class NumerarMetasCommand extends Command
{
    public function __construct(
        private readonly NumerarMetasAntigasUseCase $useCase,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só lista quantas metas cada escritório teria numeradas, sem gravar')
            ->addOption('escritorio', null, InputOption::VALUE_REQUIRED, 'Numera só o escritório com este id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $escritorio = $input->getOption('escritorio');
        if ($escritorio !== null && (!is_string($escritorio) || preg_match('/^[1-9][0-9]*$/', $escritorio) !== 1)) {
            $io->error('O --escritorio precisa ser o id numérico do escritório.');

            return Command::INVALID;
        }
        $tenantId = $escritorio === null ? null : (int) $escritorio;

        if ($input->getOption('dry-run') === true) {
            $pendentes = $this->useCase->simular($tenantId);
            $this->listar($io, $pendentes, 'seriam numeradas');
            $io->note('Simulação: nada foi gravado.');

            return Command::SUCCESS;
        }

        $this->listar($io, $this->useCase->executar($tenantId), 'numeradas');

        return Command::SUCCESS;
    }

    /** @param array<int, int> $porEscritorio */
    private function listar(SymfonyStyle $io, array $porEscritorio, string $verbo): void
    {
        if ($porEscritorio === []) {
            $io->success('Nenhuma meta sem número. Nada a fazer.');

            return;
        }

        $linhas = [];
        foreach ($porEscritorio as $tenantId => $total) {
            $linhas[] = [$tenantId, $total];
        }
        $io->table(['Escritório', 'Metas ' . $verbo], $linhas);
        $io->success(sprintf('%d meta(s) %s em %d escritório(s).', array_sum($porEscritorio), $verbo, count($porEscritorio)));
    }
}
