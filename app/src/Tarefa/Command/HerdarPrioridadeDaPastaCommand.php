<?php

declare(strict_types=1);

namespace App\Tarefa\Command;

use App\Tarefa\UseCase\HerdarPrioridadeDaPastaUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill da prioridade da meta (desenho 04 de Minhas Metas): as metas que já existiam nascem `normal`
 * na migration e herdam aqui a prioridade da pasta. Rodar uma vez depois da publicação (DEPLOY.md);
 * rodar de novo não muda nada.
 */
#[AsCommand(name: 'app:tarefa:herdar-prioridade-da-pasta', description: 'Dá às metas ainda normais a prioridade herdada da pasta, por escritório')]
final class HerdarPrioridadeDaPastaCommand extends Command
{
    public function __construct(
        private readonly HerdarPrioridadeDaPastaUseCase $useCase,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só lista quantas metas cada escritório teria atualizadas, sem gravar')
            ->addOption('escritorio', null, InputOption::VALUE_REQUIRED, 'Atualiza só o escritório com este id');
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
            $this->listar($io, $this->useCase->simular($tenantId), 'seriam atualizadas');
            $io->note('Simulação: nada foi gravado.');

            return Command::SUCCESS;
        }

        $this->listar($io, $this->useCase->executar($tenantId), 'atualizadas');

        return Command::SUCCESS;
    }

    /** @param array<int, int> $porEscritorio */
    private function listar(SymfonyStyle $io, array $porEscritorio, string $verbo): void
    {
        if ($porEscritorio === []) {
            $io->success('Nenhuma meta para herdar a prioridade da pasta. Nada a fazer.');

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
