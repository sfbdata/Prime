<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fase 0 — vincula pastas existentes ao Drive via mapeamento.csv (drive_id ↔ pasta_id).
 * Não-destrutivo: grava apenas pasta.drive_folder_id. Idempotente.
 */
#[AsCommand(
    name: 'app:sync:backfill-pastas',
    description: 'Vincula pastas existentes ao Drive (grava drive_folder_id) a partir do mapeamento.csv',
)]
final class BackfillPastasCommand extends Command
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Caminho do mapeamento.csv')
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do tenant alvo')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $conn   = $this->em->getConnection();
        $csv    = $input->getOption('csv');
        $tid    = $input->getOption('tenant-id');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($csv === null || !is_file($csv) || !is_readable($csv)) {
            $io->error('CSV inexistente ou ilegível.');

            return Command::FAILURE;
        }

        if ($tid === null || $this->tenantRepository->find((int) $tid) === null) {
            $io->error('Tenant inválido.');

            return Command::FAILURE;
        }

        $handle = fopen($csv, 'r');
        if ($handle === false) {
            $io->error('Não foi possível abrir o CSV.');

            return Command::FAILURE;
        }

        if (fread($handle, 3) !== self::UTF8_BOM) {
            rewind($handle);
        }
        $cabecalho = fgetcsv($handle, 0, ';', '"');
        if ($cabecalho === false) {
            fclose($handle);
            $io->error('CSV vazio.');

            return Command::FAILURE;
        }
        $col = array_flip(array_map(static fn (string $c): string => trim($c, "\" \t"), $cabecalho));
        if (!isset($col['drive_id'], $col['pasta_id'])) {
            fclose($handle);
            $io->error('CSV precisa das colunas drive_id e pasta_id.');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $conn->beginTransaction();
        }

        $vinculadas = 0;
        $jaTinham   = 0;
        $ignoradas  = 0;

        while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
            $driveId = trim($row[$col['drive_id']] ?? '');
            $pastaId = (int) trim($row[$col['pasta_id']] ?? '');
            if ($driveId === '' || $pastaId === 0) {
                $ignoradas++;
                continue;
            }

            $atual = $conn->fetchOne(
                'SELECT drive_folder_id FROM pasta WHERE id = :id AND tenant_id = :t',
                ['id' => $pastaId, 't' => (int) $tid],
            );
            if ($atual === false) {
                $ignoradas++;
                continue;
            }
            if ($atual !== null && $atual !== '') {
                $jaTinham++;
                continue;
            }

            $conn->executeStatement(
                'UPDATE pasta SET drive_folder_id = :d WHERE id = :id AND tenant_id = :t',
                ['d' => $driveId, 'id' => $pastaId, 't' => (int) $tid],
            );
            $vinculadas++;
        }

        fclose($handle);

        if ($dryRun && $conn->isTransactionActive()) {
            $conn->rollBack();
        }

        $io->section('Resumo do backfill de pastas');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Vinculadas (simulado)' : 'Vinculadas', $vinculadas],
            ['Já vinculadas (puladas)', $jaTinham],
            ['Ignoradas (sem pasta/linha inválida)', $ignoradas],
        ]);

        return Command::SUCCESS;
    }
}
