<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Command\AcervoNomesParser;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\CriarPastaUseCase;
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
 * Fase 1 — motor de reconciliação de PASTAS (bidirecional, aditivo, nunca apaga).
 * Rodado à mão (Fase 1a) e, depois, por cron (Fase 1b). Identidade por drive_folder_id.
 * Arquivos ficam para o incremento seguinte.
 *
 * Multi-tenant (D6): o Shared Drive é único/global (env). O comando opera no tenant dado no
 * DB, mas o Drive não é escopado por tenant — rodar para o tenant "errado" é erro de operador
 * até existir config de Drive por tenant. Por isso loga tenant + drive no início (conferência).
 */
#[AsCommand(
    name: 'app:sync:reconciliar',
    description: 'Reconcilia pastas entre sistema e Drive (bidirecional, aditivo)',
)]
final class ReconciliarCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly GoogleDriveClientInterface $drive,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly AcervoNomesParser $parser,
        private readonly string $googleDriveSharedDriveId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do tenant alvo')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário criadoPor das pastas nascidas no Drive')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula (sem mutação no Drive; DB revertido)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Processa só as N primeiras de cada via (amostra)')
            ->addOption('pasta-id', null, InputOption::VALUE_REQUIRED, 'Processa só uma pasta do sistema→Drive (debug); pula a via Drive→sistema');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();
        $tid  = $input->getOption('tenant-id');
        $uid  = $input->getOption('usuario-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        $tenant = $tid !== null ? $this->tenantRepository->find((int) $tid) : null;
        if ($tenant === null) {
            $io->error('Tenant inválido.');

            return Command::FAILURE;
        }

        $usuario = $uid !== null ? $this->em->find(User::class, (int) $uid) : null;
        if (!$usuario instanceof User) {
            $io->error('Usuário inválido.');

            return Command::FAILURE;
        }

        if (trim($this->googleDriveSharedDriveId) === '') {
            $io->error('GOOGLE_DRIVE_SHARED_DRIVE_ID não configurado.');

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            'Reconciliando <info>tenant %d</info> contra Shared Drive <info>%s</info>%s',
            (int) $tid,
            $this->googleDriveSharedDriveId,
            $dryRun ? ' <comment>(dry-run)</comment>' : '',
        ));

        // Lock: impede rodadas concorrentes (cron + manual) para o mesmo tenant.
        $lock = fopen(sys_get_temp_dir() . '/jusprime-sync-reconciliar-' . (int) $tid . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->warning('Outra reconciliação já está em execução para este tenant. Abortando.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $conn->beginTransaction();
        }

        $totais = ['criadasNoDrive' => 0, 'criadasNoSistema' => 0, 'semNup' => 0, 'divergencias' => 0, 'erros' => 0];
        $fatal  = false;

        try {
            $fatal = !$this->sistemaParaDrive($input, $tenant, $dryRun, $limit, $totais, $io);
            if (!$fatal && $input->getOption('pasta-id') === null) {
                $fatal = !$this->driveParaSistema((int) $tid, (int) $uid, $dryRun, $limit, $totais, $io);
            }
        } finally {
            if ($dryRun && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $io->section('Resumo da reconciliação de pastas');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Pastas criadas no Drive (simulado)' : 'Pastas criadas no Drive', $totais['criadasNoDrive']],
            [$dryRun ? 'Pastas criadas no sistema (simulado)' : 'Pastas criadas no sistema', $totais['criadasNoSistema']],
            ['Pastas do Drive sem NUP (puladas)', $totais['semNup']],
            ['Divergências de nome (só reporta)', $totais['divergencias']],
            ['Erros por item (logados)', $totais['erros']],
        ]);

        if ($fatal) {
            $io->error('Rodada interrompida: o EntityManager fechou após um erro grave. Reexecute (é idempotente).');

            return Command::FAILURE;
        }
        if ($totais['erros'] > 0) {
            $io->warning(sprintf('%d item(ns) com erro — logados acima; a reexecução é idempotente.', $totais['erros']));
        }

        return Command::SUCCESS;
    }

    /**
     * Via sistema→Drive: cada Pasta sem drive_folder_id ganha uma pasta no Drive.
     * Itera por IDs e faz flush()+clear() por item (evita OOM, §10.4).
     *
     * @param array<string, int> $totais
     * @return bool false se o EM fechou (fatal)
     */
    private function sistemaParaDrive(InputInterface $input, Tenant $tenant, bool $dryRun, ?int $limit, array &$totais, SymfonyStyle $io): bool
    {
        $conn = $this->em->getConnection();
        $sql  = 'SELECT id FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NULL';
        $params = ['t' => $tenant->getId()];
        if ($input->getOption('pasta-id') !== null) {
            $sql .= ' AND id = :pid';
            $params['pid'] = (int) $input->getOption('pasta-id');
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        /** @var list<int> $ids */
        $ids = array_map('intval', $conn->fetchFirstColumn($sql, $params));

        if ($dryRun) {
            $totais['criadasNoDrive'] += count($ids);

            return true;
        }

        foreach ($ids as $id) {
            $pasta = $this->em->find(Pasta::class, $id);
            if ($pasta === null) {
                continue;
            }
            try {
                $folderId = $this->drive->criarPasta($this->nomePastaDrive($pasta), $this->googleDriveSharedDriveId);
                $pasta->setDriveFolderId($folderId);
                $pasta->setDriveSyncedAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->em->clear();
                $totais['criadasNoDrive']++;
            } catch (\Throwable $e) {
                $totais['erros']++;
                $io->writeln(sprintf('  <error>[erro]</error> pasta_id=%d (sistema→Drive): %s', $id, $e->getMessage()));
                if (!$this->em->isOpen()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Via Drive→sistema: subpasta sem par vira Pasta (NUP extraído); sem NUP numérico pula (D9);
     * divergência de nome só reporta (D10). Map de vínculos é escalar (memory-light).
     *
     * @param array<string, int> $totais
     * @return bool false se o EM fechou (fatal)
     */
    private function driveParaSistema(int $tenantId, int $usuarioId, bool $dryRun, ?int $limit, array &$totais, SymfonyStyle $io): bool
    {
        $conn = $this->em->getConnection();
        // Map escalar drive_folder_id → nome esperado (não hidrata entidades).
        $rows = $conn->fetchAllAssociative(
            'SELECT drive_folder_id, nup, nome_cliente, nome_acao FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NOT NULL',
            ['t' => $tenantId],
        );
        $linkadas = [];
        foreach ($rows as $r) {
            $linkadas[(string) $r['drive_folder_id']] = $this->nomeEsperado(
                (string) $r['nup'],
                $r['nome_cliente'] !== null ? (string) $r['nome_cliente'] : null,
                $r['nome_acao'] !== null ? (string) $r['nome_acao'] : null,
            );
        }

        $subs = $this->drive->listarSubpastas($this->googleDriveSharedDriveId);
        if ($limit !== null) {
            $subs = array_slice($subs, 0, $limit);
        }

        foreach ($subs as $sub) {
            if (isset($linkadas[$sub['id']])) {
                if ($linkadas[$sub['id']] !== $sub['nome']) {
                    $totais['divergencias']++;
                    $io->writeln(sprintf(
                        '  <comment>[divergência]</comment> drive_folder_id=%s: sistema="%s" drive="%s"',
                        $sub['id'], $linkadas[$sub['id']], $sub['nome'],
                    ));
                }

                continue;
            }

            $r    = $this->parser->parsear($sub['nome']);
            $item = $r['alta'][0] ?? $r['revisao'][0] ?? null;
            // D9: sem NUP numérico extraível → pula e reporta (não cria pasta com NUP lixo).
            if ($item === null || !ctype_digit(trim($item['nup']))) {
                $totais['semNup']++;
                $io->writeln(sprintf('  <comment>[sem NUP]</comment> "%s" — pulada (D9)', $sub['nome']));

                continue;
            }

            try {
                // getReference: proxies leves, válidos mesmo após em->clear() da iteração anterior.
                $tenant  = $this->em->getReference(Tenant::class, $tenantId);
                $usuario = $this->em->getReference(User::class, $usuarioId);
                $dto = new CriarPastaDTO(
                    nup: $item['nup'],
                    nomeCliente: $item['cliente'] !== '' ? $item['cliente'] : null,
                    nomeAcao: $item['acao'] !== '' ? $item['acao'] : null,
                );
                $pasta = $this->criarPasta->executar($dto, $usuario, $tenant);
                $pasta->setDriveFolderId($sub['id']);
                $pasta->setDriveSyncedAt(new \DateTimeImmutable());
                $this->em->flush(); // necessário: executar() persistiu sem driveFolderId; aqui grava o vínculo
                $this->em->clear();
                $totais['criadasNoSistema']++;
            } catch (\Throwable $e) {
                $totais['erros']++;
                $io->writeln(sprintf('  <error>[erro]</error> drive_folder_id=%s (Drive→sistema): %s', $sub['id'], $e->getMessage()));
                if (!$this->em->isOpen()) {
                    return false;
                }
            }
        }

        return true;
    }

    private function nomePastaDrive(Pasta $pasta): string
    {
        return $this->nomeEsperado($pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao());
    }

    private function nomeEsperado(?string $nup, ?string $cliente, ?string $acao): string
    {
        $partes = array_filter([$nup, $cliente, $acao], static fn (?string $v): bool => $v !== null && $v !== '');

        return implode(' - ', $partes);
    }
}
