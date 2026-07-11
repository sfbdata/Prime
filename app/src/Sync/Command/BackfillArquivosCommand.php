<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Pasta\Entity\Pasta;
use App\Repository\TenantRepository;
use App\Sync\Service\GoogleDriveClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fase 0 — casa PastaDocumento existentes com os arquivos do Drive por nome_original
 * e grava pasta_documento.drive_file_id. Anti-duplicação da 1ª reconciliação (risco R1).
 * Não-destrutivo: só grava o vínculo.
 *
 * Itera por IDs e recarrega cada pasta com find() para poder dar flush()+clear() por
 * pasta sem detachar as demais (padrão do CopiarArquivosAcervoCommand).
 */
#[AsCommand(
    name: 'app:sync:backfill-arquivos',
    description: 'Vincula arquivos existentes ao Drive (grava drive_file_id) casando por nome_original',
)]
final class BackfillArquivosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly GoogleDriveClientInterface $drive,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do tenant alvo')
            ->addOption('pasta-id', null, InputOption::VALUE_REQUIRED, 'Processar só uma pasta (debug)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $conn   = $this->em->getConnection();
        $tid    = $input->getOption('tenant-id');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($tid === null || $this->tenantRepository->find((int) $tid) === null) {
            $io->error('Tenant inválido.');

            return Command::FAILURE;
        }

        // Coleta os IDs primeiro (escalar) para poder recarregar cada pasta no loop.
        $sql    = 'SELECT id FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NOT NULL';
        $params = ['t' => (int) $tid];
        if ($input->getOption('pasta-id') !== null) {
            $sql .= ' AND id = :pid';
            $params['pid'] = (int) $input->getOption('pasta-id');
        }
        /** @var list<int> $pastaIds */
        $pastaIds = array_map('intval', $conn->fetchFirstColumn($sql, $params));

        if ($dryRun) {
            $conn->beginTransaction();
        }

        $vinculados      = 0;
        $jaTinham        = 0;
        $semParNoDrive   = 0; // doc do sistema sem arquivo correspondente no Drive
        $semParNoSistema = 0; // arquivo do Drive sem doc correspondente (o motor cria depois)
        $colisoes        = 0; // drive_file_id que já seria usado por outro doc (evita violar o UNIQUE)
        /** @var array<string, true> $idsAtribuidos run-wide: sobrevive ao em->clear() por ser array PHP */
        $idsAtribuidos = [];

        foreach ($pastaIds as $pastaId) {
            $pasta = $this->em->find(Pasta::class, $pastaId);
            if ($pasta === null) {
                continue;
            }
            $folderId = $pasta->getDriveFolderId();
            if ($folderId === null || $folderId === '') {
                continue;
            }

            // Casamento por nome_original EXATO (case-sensitive) — mesma chave da carga de maio
            // (CopiarArquivosAcervoCommand). $todosIdsDrive guarda todos os ids p/ contar órfãos.
            $doDrive       = [];
            $todosIdsDrive = [];
            foreach ($this->drive->listarArquivos($folderId) as $arq) {
                $doDrive[$arq['nome']]        = $arq['id'];
                $todosIdsDrive[$arq['id']]    = true;
            }

            /** @var array<string, true> $idsCasados ids do Drive já ligados a um doc nesta pasta */
            $idsCasados = [];

            foreach ($pasta->getDocumentos() as $doc) {
                $atual = $doc->getDriveFileId();
                if ($atual !== null && $atual !== '') {
                    $jaTinham++;
                    $idsCasados[$atual] = true;
                    continue;
                }
                $chave = $doc->getNomeOriginal();
                if (!isset($doDrive[$chave])) {
                    $semParNoDrive++;
                    continue;
                }
                $driveId = $doDrive[$chave];
                if (isset($idsAtribuidos[$driveId])) {
                    // Mesmo drive_file_id já atribuído a outro doc nesta rodada: não viola o
                    // UNIQUE, reporta a colisão para conferência humana (§10.6).
                    $colisoes++;
                    continue;
                }
                $doc->setDriveFileId($driveId);
                $idsAtribuidos[$driveId] = true;
                $idsCasados[$driveId]    = true;
                $vinculados++;
            }

            foreach ($todosIdsDrive as $driveId => $_) {
                if (!isset($idsCasados[$driveId])) {
                    $semParNoSistema++;
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        if ($dryRun && $conn->isTransactionActive()) {
            $conn->rollBack();
        }

        $io->section('Resumo do backfill de arquivos');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Vinculados (simulado)' : 'Vinculados', $vinculados],
            ['Já vinculados (pulados)', $jaTinham],
            ['Docs do sistema sem par no Drive', $semParNoDrive],
            ['Arquivos do Drive sem par no sistema (viram criação futura)', $semParNoSistema],
            ['Colisões de nome puladas (mesmo drive_file_id)', $colisoes],
        ]);

        return Command::SUCCESS;
    }
}
