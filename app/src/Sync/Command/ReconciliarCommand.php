<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Command\AcervoNomesParser;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Repository\TenantRepository;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\Service\GoogleDriveClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fase 1 — motor de reconciliação bidirecional (aditivo, nunca apaga) de PASTAS e ARQUIVOS.
 * Rodado à mão (Fase 1a) e, depois, por cron (Fase 1b). Identidade por drive_folder_id /
 * drive_file_id. Seção vira subpasta-espelho no Drive (por nome); sub-subpasta é achatada
 * para a seção-avó (§11.6). Backfill (Fase 0) já linkou os pré-existentes por nome_original.
 *
 * Multi-tenant (D6): o Shared Drive é único/global (env). O comando opera no tenant dado no
 * DB, mas o Drive não é escopado por tenant — rodar para o tenant "errado" é erro de operador
 * até existir config de Drive por tenant. Por isso loga tenant + drive no início (conferência).
 */
#[AsCommand(
    name: 'app:sync:reconciliar',
    description: 'Reconcilia pastas e arquivos entre sistema e Drive (bidirecional, aditivo)',
)]
final class ReconciliarCommand extends Command
{
    /** Teto de pasta_documento.tamanho_bytes (coluna INT4 do Postgres). */
    private const TAMANHO_MAX_INT4 = 2147483647;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly GoogleDriveClientInterface $drive,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly AcervoNomesParser $parser,
        private readonly PastaSecaoRepository $secaoRepository,
        private readonly ArquivoStorageInterface $storage,
        #[Autowire('%uploads_dir%')]
        private readonly string $uploadsDir,
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
            ->addOption('pasta-id', null, InputOption::VALUE_REQUIRED, 'Processa só uma pasta do sistema→Drive (debug); pula a via Drive→sistema')
            ->addOption('skip-arquivos', null, InputOption::VALUE_NONE, 'Reconcilia só PASTAS (pula a sincronização de arquivos) — a carga de arquivos fica para fase própria');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();
        $tid  = $input->getOption('tenant-id');
        $uid  = $input->getOption('usuario-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $skipArquivos = (bool) $input->getOption('skip-arquivos');

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

        $totais = [
            'criadasNoDrive'   => 0,
            'criadasNoSistema' => 0,
            'semNup'           => 0,
            'divergencias'     => 0,
            'erros'            => 0,
            'arquivosEnviados' => 0,
            'arquivosBaixados' => 0,
            'secoesArquivos'   => 0,
            'googleNative'       => 0,
            'ignoradosTamanho'   => 0,
            'ignoradosNome'      => 0,
            'ignoradosDuplicados' => 0,
        ];
        $fatal  = false;

        try {
            $fatal = !$this->sistemaParaDrive($input, $tenant, $dryRun, $limit, $totais, $io);
            if (!$fatal && $input->getOption('pasta-id') === null) {
                $fatal = !$this->driveParaSistema((int) $tid, (int) $uid, $dryRun, $limit, $totais, $io);
            }
            if (!$fatal && !$skipArquivos) {
                $pastaIdOpt = $input->getOption('pasta-id') !== null ? (int) $input->getOption('pasta-id') : null;
                $fatal = !$this->reconciliarArquivos((int) $tid, $pastaIdOpt, $dryRun, $limit, $totais, $io);
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
        ]);

        if ($skipArquivos) {
            $io->note('Sincronização de arquivos PULADA (--skip-arquivos): só as pastas foram reconciliadas.');
        }

        $io->section('Resumo da reconciliação de arquivos');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Arquivos enviados ao Drive (simulado)' : 'Arquivos enviados ao Drive', $totais['arquivosEnviados']],
            [$dryRun ? 'Arquivos baixados do Drive (simulado)' : 'Arquivos baixados do Drive', $totais['arquivosBaixados']],
            ['Seções criadas (Drive→sistema)', $totais['secoesArquivos']],
            ['Arquivos Google-native pulados', $totais['googleNative']],
            ['Arquivos ignorados por tamanho (coluna INT4)', $totais['ignoradosTamanho']],
            ['Arquivos ignorados por nome > 255 chars', $totais['ignoradosNome']],
            ['Arquivos já vinculados a outra pasta (pulados)', $totais['ignoradosDuplicados']],
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
            // D9: sem NUP extraível → pula e reporta (não cria pasta com NUP lixo).
            // NUP aceita sufixo de letra (desambiguação de repetidos: 10, 10A, 10B).
            if ($item === null || !$this->ehNupValido($item['nup'])) {
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

    /**
     * NUP válido para criar Pasta: dígitos com sufixo opcional de uma letra
     * (desambiguação de número repetido — ex.: 10, 10A, 10B). Case-insensitive;
     * Pasta::setNup normaliza para maiúsculo.
     */
    private function ehNupValido(string $nup): bool
    {
        return preg_match('/^\d+[A-Za-z]?$/', trim($nup)) === 1;
    }

    /**
     * Via de ARQUIVOS (§10.3 passo 3), por Pasta vinculada (drive_folder_id != null).
     *
     * @param array<string, int> $totais
     * @return bool false se o EM fechou (fatal)
     */
    private function reconciliarArquivos(int $tenantId, ?int $pastaIdOpt, bool $dryRun, ?int $limit, array &$totais, SymfonyStyle $io): bool
    {
        $conn   = $this->em->getConnection();
        $sql    = 'SELECT id FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NOT NULL';
        $params = ['t' => $tenantId];
        if ($pastaIdOpt !== null) {
            $sql .= ' AND id = :pid';
            $params['pid'] = $pastaIdOpt;
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        /** @var list<int> $ids */
        $ids = array_map('intval', $conn->fetchFirstColumn($sql, $params));

        foreach ($ids as $id) {
            if (!$this->processarArquivosDaPasta($id, $dryRun, $totais, $io)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reconcilia os arquivos de UMA pasta vinculada, nas duas vias. Trabalha por dados ESCALARES
     * (ids, caminhos) e faz flush()+clear() por ITEM: a mutação no Drive e a gravação do vínculo
     * ficam atômicas, então uma falha de item nunca descarta o vínculo já feito nem re-duplica na
     * re-execução (D5/R1). Ordem sistema→Drive antes de Drive→sistema: o recém-enviado (id em
     * $conhecidos) não é reimportado. Identidade sempre por drive_file_id.
     *
     * @param array<string, int> $totais
     * @return bool false se o EM fechou (fatal)
     */
    private function processarArquivosDaPasta(int $pastaId, bool $dryRun, array &$totais, SymfonyStyle $io): bool
    {
        $conn = $this->em->getConnection();

        $row = $conn->fetchAssociative('SELECT tenant_id, drive_folder_id FROM pasta WHERE id = :id', ['id' => $pastaId]);
        if ($row === false || $row['drive_folder_id'] === null) {
            return true;
        }
        $tenantId   = (int) $row['tenant_id'];
        $caseFolder = (string) $row['drive_folder_id'];

        // drive_file_id já conhecidos desta pasta (escalar → sobrevive ao clear). Identidade da idempotência.
        /** @var array<string, true> $conhecidos */
        $conhecidos = [];
        foreach ($conn->fetchFirstColumn('SELECT drive_file_id FROM pasta_documento WHERE pasta_id = :p AND drive_file_id IS NOT NULL', ['p' => $pastaId]) as $fid) {
            $conhecidos[(string) $fid] = true;
        }

        // Subpastas imediatas do caso, capturadas UMA vez: a lista crua guia a varredura Drive→sistema
        // (não perde irmãs que colidem em UPPER); o mapa nomeUPPER→id acha a subpasta-espelho no push.
        $subsRaw = $this->drive->listarSubpastas($caseFolder);
        /** @var array<string, string> $subpastasDoCaso */
        $subpastasDoCaso = [];
        foreach ($subsRaw as $sub) {
            $subpastasDoCaso[mb_strtoupper($sub['nome'])] = $sub['id'];
        }

        // --- Via A — sistema→Drive: cada doc sem drive_file_id sobe (seção vira subpasta-espelho, Fork 1). ---
        $docRows = $conn->fetchAllAssociative(
            'SELECT id, caminho_arquivo FROM pasta_documento WHERE pasta_id = :p AND drive_file_id IS NULL ORDER BY id ASC',
            ['p' => $pastaId],
        );
        foreach ($docRows as $docRow) {
            $caminho = $this->storage->caminho($this->uploadsDir, (string) $docRow['caminho_arquivo']);
            // Checagem read-only, faz sentido no dry-run também (preview fiel ao lote real, §10.6/passo 4).
            if (!$this->storage->existe($caminho)) {
                $totais['erros']++;
                $io->writeln(sprintf('  <error>[erro]</error> doc_id=%d: arquivo físico ausente (%s)', (int) $docRow['id'], (string) $docRow['caminho_arquivo']));

                continue;
            }
            if ($dryRun) {
                $totais['arquivosEnviados']++;

                continue;
            }
            $doc = $this->em->find(PastaDocumento::class, (int) $docRow['id']);
            if ($doc === null) {
                continue;
            }
            try {
                $alvo   = $this->resolverPastaAlvoNoDrive($doc->getSecao(), $caseFolder, $subpastasDoCaso);
                $fileId = $this->drive->enviarArquivo($alvo, $doc->getNomeOriginal(), $caminho, $doc->getMimeType());
                // Marca como conhecido ANTES do flush: o arquivo JÁ está no Drive; se o flush do vínculo
                // falhar, a Via B (mesma rodada) não pode reimportá-lo como novo (evita duplicar o doc).
                $conhecidos[$fileId] = true;
                $doc->setDriveFileId($fileId);
                $this->em->flush();
                $this->em->clear();
                $totais['arquivosEnviados']++;
            } catch (\Throwable $e) {
                $totais['erros']++;
                $io->writeln(sprintf('  <error>[erro]</error> doc_id=%d (sistema→Drive): %s', (int) $docRow['id'], $e->getMessage()));
                if (!$this->em->isOpen()) {
                    return false;
                }
                $this->em->clear();
            }
        }

        // --- Via B — Drive→sistema (recursivo, §11.6). ---
        // Seção resolvida por nome-UPPER e criada preguiçosamente (só no 1º arquivo importável → sem
        // seção fantasma). O id memoizado sobrevive aos clears por item.
        /** @var array<string, int> $secaoIds */
        $secaoIds = [];

        // Arquivos na raiz do caso → sem seção.
        foreach ($this->drive->listarArquivos($caseFolder) as $arq) {
            if (!$this->baixarArquivo($arq, null, $secaoIds, $pastaId, $tenantId, $conhecidos, $dryRun, $totais, $io)) {
                return false;
            }
        }
        // Subpastas de 1º nível: tudo abaixo é achatado para a seção daquela subpasta (seção-avó).
        foreach ($subsRaw as $sub) {
            $nomeUP = mb_strtoupper($sub['nome']);
            foreach ($this->coletarArquivosRecursivo($sub['id']) as $arq) {
                if (!$this->baixarArquivo($arq, $nomeUP, $secaoIds, $pastaId, $tenantId, $conhecidos, $dryRun, $totais, $io)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Resolve a pasta-alvo no Drive para um doc que sobe: raiz do caso se sem seção; senão a
     * subpasta-espelho da seção (find-or-create por nome — Fork 1). Atualiza o mapa de subpastas.
     *
     * @param array<string, string> $subpastasDoCaso
     */
    private function resolverPastaAlvoNoDrive(?PastaSecao $secao, string $caseFolder, array &$subpastasDoCaso): string
    {
        if ($secao === null) {
            return $caseFolder;
        }
        $nomeUP = $secao->getNome(); // já UPPER
        if (isset($subpastasDoCaso[$nomeUP])) {
            return $subpastasDoCaso[$nomeUP];
        }
        $novo = $this->drive->criarPasta($nomeUP, $caseFolder);
        $subpastasDoCaso[$nomeUP] = $novo;

        return $novo;
    }

    /**
     * Todos os arquivos de $folderId e de qualquer subpasta abaixo (achatamento §11.6).
     *
     * @return list<array{id: string, nome: string, tamanho: int, mimeType: string}>
     */
    private function coletarArquivosRecursivo(string $folderId): array
    {
        $arquivos = $this->drive->listarArquivos($folderId);
        foreach ($this->drive->listarSubpastas($folderId) as $sub) {
            foreach ($this->coletarArquivosRecursivo($sub['id']) as $arq) {
                $arquivos[] = $arq;
            }
        }

        return $arquivos;
    }

    /**
     * Acha (por nome, na pasta) ou cria a PastaSecao e retorna seu id, com flush+clear próprio — o id
     * sobrevive aos clears dos downloads seguintes. Memoiza em $secaoIds. Como cada seção é flushada
     * antes da próxima, proximaOrdem() enxerga as já criadas (ordem correta).
     *
     * @param array<string, int> $secaoIds
     * @param array<string, int> $totais
     */
    private function resolverSecaoId(string $nomeUP, array &$secaoIds, int $pastaId, int $tenantId, array &$totais, SymfonyStyle $io): ?int
    {
        if (isset($secaoIds[$nomeUP])) {
            return $secaoIds[$nomeUP];
        }
        $conn      = $this->em->getConnection();
        $existente = $conn->fetchOne('SELECT id FROM pasta_secao WHERE pasta_id = :p AND nome = :n', ['p' => $pastaId, 'n' => $nomeUP]);
        if ($existente !== false) {
            $secaoIds[$nomeUP] = (int) $existente;

            return (int) $existente;
        }
        try {
            $pasta = $this->em->find(Pasta::class, $pastaId);
            if ($pasta === null) {
                return null;
            }
            $tenant = $this->em->getReference(Tenant::class, $tenantId);
            $secao  = (new PastaSecao())
                ->setNome($nomeUP)
                ->setPasta($pasta)
                ->setTenant($tenant)
                ->setOrdem($this->secaoRepository->proximaOrdem($pasta, $tenant));
            $this->em->persist($secao);
            $this->em->flush();
            $id = (int) $secao->getId();
            $this->em->clear();
            $secaoIds[$nomeUP] = $id;
            $totais['secoesArquivos']++;

            return $id;
        } catch (\Throwable $e) {
            $io->writeln(sprintf('  <error>[erro]</error> seção "%s" (pasta_id=%d): %s', $nomeUP, $pastaId, $e->getMessage()));
            if ($this->em->isOpen()) {
                $this->em->clear();
            }

            return null;
        }
    }

    /**
     * Baixa um arquivo do Drive e cria o PastaDocumento vinculado — se ainda não houver par por ID.
     * flush+clear por item. Pula Google-native, nome > 255 e o que excede o INT4 de tamanho_bytes.
     * A seção (quando houver) só é materializada aqui, no 1º arquivo importável (sem seção fantasma).
     *
     * @param array{id: string, nome: string, tamanho: int, mimeType: string} $arq
     * @param array<string, int>  $secaoIds   memo nomeUPPER→id (sobrevive ao clear)
     * @param array<string, true> $conhecidos
     * @param array<string, int>  $totais
     * @return bool false se o EM fechou (fatal)
     */
    private function baixarArquivo(array $arq, ?string $secaoNomeUP, array &$secaoIds, int $pastaId, int $tenantId, array &$conhecidos, bool $dryRun, array &$totais, SymfonyStyle $io): bool
    {
        // Google-native (Docs/Sheets/…): não têm conteúdo binário para baixar → pula.
        if (str_starts_with($arq['mimeType'], 'application/vnd.google-apps.')) {
            $totais['googleNative']++;

            return true;
        }
        // Idempotência por ID: já vinculado (backfill) ou recém-enviado nesta rodada.
        if (isset($conhecidos[$arq['id']])) {
            return true;
        }
        // Guard do UNIQUE GLOBAL de drive_file_id: o mesmo arquivo pode estar vinculado a outra pasta
        // (arquivo multi-parent no Drive). Sem isto, o INSERT violaria uniq_pasta_documento_drive_file_id
        // e fecharia o EM (abortando a rodada). Detecta antes e pula (read-only, vale no dry-run também).
        if ((int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE drive_file_id = :id', ['id' => $arq['id']]) > 0) {
            $totais['ignoradosDuplicados']++;

            return true;
        }
        // Nome não cabe em titulo/nome_original (varchar 255) → pula e reporta (não envenena o insert).
        if (mb_strlen($arq['nome']) > 255) {
            $totais['ignoradosNome']++;
            $io->writeln(sprintf('  <comment>[ignorado]</comment> nome > 255 caracteres (drive_file_id=%s) — pulado', $arq['id']));

            return true;
        }
        // tamanho_bytes é INT4 no schema: acima do teto não cabe → pula e reporta.
        if ($arq['tamanho'] > self::TAMANHO_MAX_INT4) {
            $totais['ignoradosTamanho']++;
            $io->writeln(sprintf('  <comment>[ignorado]</comment> "%s" (%d bytes) acima do limite da coluna de tamanho — pulado', $arq['nome'], $arq['tamanho']));

            return true;
        }
        if ($dryRun) {
            $totais['arquivosBaixados']++;

            return true;
        }

        // Seção materializada preguiçosamente (só agora, para um arquivo que SERÁ importado).
        $secaoId = null;
        if ($secaoNomeUP !== null) {
            $secaoId = $this->resolverSecaoId($secaoNomeUP, $secaoIds, $pastaId, $tenantId, $totais, $io);
            if ($secaoId === null) {
                if (!$this->em->isOpen()) {
                    return false;
                }
                $totais['erros']++;

                return true;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jusprime-sync-');
        if ($tmp === false) {
            $totais['erros']++;
            $io->writeln(sprintf('  <error>[erro]</error> drive_file_id=%s: falha ao criar arquivo temporário', $arq['id']));

            return true;
        }
        $nomeStorage = null;
        try {
            // TODO (Fork 2 / D8): baixar em streaming direto para o storage, sem carregar o arquivo
            // inteiro em memória. O encanamento resumável/streaming fica para a Fase 1a (validação
            // manual contra o Shared Drive real). O motor já é path-based, então não há retrabalho aqui.
            $this->drive->baixarArquivo($arq['id'], $tmp);
            $conteudo = file_get_contents($tmp);
            if ($conteudo === false) {
                throw new \RuntimeException('Falha ao ler o arquivo baixado do Drive.');
            }
            $extensao = pathinfo($arq['nome'], PATHINFO_EXTENSION);
            if ($extensao === '') {
                $extensao = 'bin';
            }
            $nomeStorage = $this->storage->salvarConteudo($conteudo, $this->uploadsDir, $extensao);

            $pasta = $this->em->find(Pasta::class, $pastaId);
            if ($pasta === null) {
                throw new \RuntimeException('Pasta desapareceu durante o download.');
            }
            $secao = $secaoId !== null ? $this->em->getReference(PastaSecao::class, $secaoId) : null;
            $doc = (new PastaDocumento())
                ->setTitulo($arq['nome'])
                ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
                ->setCaminhoArquivo($nomeStorage)
                ->setNomeOriginal($arq['nome'])
                ->setMimeType($arq['mimeType'] !== '' ? $arq['mimeType'] : 'application/octet-stream')
                ->setTamanhoBytes($arq['tamanho'])
                ->setPasta($pasta)
                ->setSecao($secao)
                ->setTenant($this->em->getReference(Tenant::class, $tenantId))
                ->setDriveFileId($arq['id']);
            $this->em->persist($doc);
            $this->em->flush();
            $this->em->clear();
            $conhecidos[$arq['id']] = true;
            $totais['arquivosBaixados']++;
        } catch (\Throwable $e) {
            $totais['erros']++;
            $io->writeln(sprintf('  <error>[erro]</error> drive_file_id=%s (Drive→sistema): %s', $arq['id'], $e->getMessage()));
            // Remove o arquivo já gravado no storage cujo doc não persistiu (evita órfão em disco).
            if ($nomeStorage !== null) {
                $this->storage->excluir($this->storage->caminho($this->uploadsDir, $nomeStorage));
            }
            if (!$this->em->isOpen()) {
                return false;
            }
            $this->em->clear();
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return true;
    }
}
