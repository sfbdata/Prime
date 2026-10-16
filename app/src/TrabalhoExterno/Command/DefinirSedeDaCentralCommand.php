<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Command;

use App\Repository\TenantRepository;
use App\TrabalhoExterno\UseCase\DefinirSedeDaCentralUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Define o escritório-sede da Central do Trabalho Externo (D2): os colaboradores dele viram a
 * Equipe do Master. `nenhuma` limpa a sede (ninguém é Equipe).
 */
#[AsCommand(name: 'trabalho-externo:definir-sede', description: 'Define o escritório-sede da Central do Trabalho Externo')]
final class DefinirSedeDaCentralCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly DefinirSedeDaCentralUseCase $definir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('escritorio', InputArgument::REQUIRED, 'Id do escritório (tenant) ou "nenhuma"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $valor = (string) $input->getArgument('escritorio');

        if ($valor === 'nenhuma') {
            $this->definir->executar(null);
            $io->success('Sede removida: ninguém é Equipe da Central até uma nova sede ser definida.');

            return Command::SUCCESS;
        }

        $tenant = ctype_digit($valor) ? $this->tenants->find((int) $valor) : null;
        if ($tenant === null) {
            $io->error(sprintf('Escritório "%s" não encontrado.', $valor));

            return Command::FAILURE;
        }

        $this->definir->executar($tenant);
        $io->success(sprintf('Sede da Central: %s (id %d).', $tenant->getName(), $tenant->getId()));

        return Command::SUCCESS;
    }
}
