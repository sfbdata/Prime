<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:dev:db:clear',
    description: 'Limpa todos os dados do banco no ambiente de desenvolvimento',
)]
class ClearDevDatabaseCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Executa sem confirmação interativa')
            ->addOption(
                'include-migrations',
                null,
                InputOption::VALUE_NONE,
                'Inclui doctrine_migration_versions na limpeza'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->kernel->getEnvironment() !== 'dev') {
            $io->error('Este comando só pode ser executado em APP_ENV=dev.');
            return Command::FAILURE;
        }

        $includeMigrations = (bool) $input->getOption('include-migrations');

        if (!(bool) $input->getOption('force')) {
            $confirmed = $io->confirm(
                'Isso removerá os dados do banco de desenvolvimento. Deseja continuar?',
                false
            );

            if (!$confirmed) {
                $io->warning('Operação cancelada.');
                return Command::SUCCESS;
            }
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();
            $tableNames = $schemaManager->listTableNames();

            if (!$includeMigrations) {
                $tableNames = array_values(array_filter(
                    $tableNames,
                    static fn (string $tableName): bool => $tableName !== 'doctrine_migration_versions'
                ));
            }

            if ($tableNames === []) {
                $io->success('Nenhuma tabela para limpar.');
                return Command::SUCCESS;
            }

            $platform = $this->connection->getDatabasePlatform();
            $quotedTables = array_map(
                static fn (string $tableName): string => $platform->quoteIdentifier(trim($tableName, '"')),
                $tableNames
            );

            $truncateSql = sprintf(
                'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
                implode(', ', $quotedTables)
            );

            $this->connection->beginTransaction();
            $this->connection->executeStatement($truncateSql);
            $this->connection->commit();

            $io->success(sprintf('Banco de desenvolvimento limpo com sucesso (%d tabela(s)).', count($tableNames)));

            if (!$includeMigrations) {
                $io->note('A tabela doctrine_migration_versions foi preservada.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->error('Falha ao limpar o banco: '.$exception->getMessage());
            return Command::FAILURE;
        }
    }
}
