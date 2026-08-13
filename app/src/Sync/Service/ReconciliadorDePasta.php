<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Motor de reconciliação de UMA pasta (aditivo, nunca apaga), extraído do ReconciliarCommand
 * para ser reutilizável: o comando o chama em loop (todas as pastas do tenant) e o handler da
 * Fase 2 o chama para a pasta de um evento. Recebe o {@see GoogleDriveClientInterface} POR PARÂMETRO
 * (o client é por-tenant, resolvido pela fábrica) e devolve contadores/mensagens num
 * {@see ResultadoReconciliacaoPasta} — sem IO (SymfonyStyle). Identidade sempre por drive_folder_id /
 * drive_file_id; flush()+clear() por item (anti-OOM, §10.4); idempotente na re-execução.
 */
final class ReconciliadorDePasta
{
    /** Teto de pasta_documento.tamanho_bytes (coluna INT4 do Postgres). */
    private const TAMANHO_MAX_INT4 = 2147483647;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly PastaSecaoRepository $secaoRepository,
        #[Autowire('%uploads_dir%')]
        private readonly string $uploadsDir,
    ) {
    }

    /** Nome esperado da pasta no Drive: "NUP - CLIENTE - AÇÃO" (partes vazias omitidas). */
    public static function nomeEsperado(?string $nup, ?string $cliente, ?string $acao): string
    {
        $partes = array_filter([$nup, $cliente, $acao], static fn (?string $v): bool => $v !== null && $v !== '');

        return implode(' - ', $partes);
    }

    /**
     * Sincroniza uma pasta ponta a ponta (garante o folder no Drive + reconcilia arquivos), para o
     * handler da Fase 2. Devolve o resultado agregado. Não lança por erro de item (fica no resultado).
     */
    public function sincronizarPasta(
        int $pastaId,
        string $rootFolderId,
        GoogleDriveClientInterface $client,
        bool $dryRun = false,
        ModoSincronizacao $modo = ModoSincronizacao::Enviar,
    ): ResultadoReconciliacaoPasta {
        $resultado = new ResultadoReconciliacaoPasta();

        if ($modo->envia()) {
            $this->garantirFolderDaPasta($pastaId, $rootFolderId, $client, $dryRun, $resultado);
            if ($resultado->fatal) {
                return $resultado;
            }
        }

        $this->reconciliarArquivosDaPasta($pastaId, $client, $dryRun, $resultado, $modo);

        return $resultado;
    }

    /**
     * Propaga o nome do sistema para o Drive (requisito R3 / D12.3): renomeia a pasta já vinculada
     * para o {@see self::nomeEsperado()} atual.
     *
     * Chamado POR EVENTO — quando o usuário edita a pasta e o nome de fato muda —, nunca por
     * varredura. A decisão do dono foi explícita: renomear as 1070 pastas a cada rodada do cron,
     * mesmo com o nome já igual, seriam ~1070 writes/hora inúteis, e write pesa mais na cota da
     * API do que leitura. Quem sabe que o nome mudou é a origem da edição, então a condição vive
     * lá — aqui não há leitura do Drive para comparar.
     *
     * No-op silencioso se a pasta ainda não tem vínculo (o envio normal cria com o nome certo).
     */
    public function renomearNoDrive(int $pastaId, GoogleDriveClientInterface $client, bool $dryRun, ResultadoReconciliacaoPasta $r): void
    {
        $conn = $this->em->getConnection();
        $row  = $conn->fetchAssociative(
            'SELECT drive_folder_id, nup, nome_cliente, nome_acao FROM pasta WHERE id = :id',
            ['id' => $pastaId],
        );

        if ($row === false || $row['drive_folder_id'] === null || $row['drive_folder_id'] === '') {
            return;
        }

        $nome = self::nomeEsperado(
            $row['nup'] === null ? null : (string) $row['nup'],
            $row['nome_cliente'] === null ? null : (string) $row['nome_cliente'],
            $row['nome_acao'] === null ? null : (string) $row['nome_acao'],
        );

        if ($nome === '') {
            return; // sem nada para nomear — não apaga o nome que está no Drive
        }

        if ($dryRun) {
            $r->renomeadasNoDrive++;

            return;
        }

        try {
            $client->renomearPasta((string) $row['drive_folder_id'], $nome);
            $r->renomeadasNoDrive++;
        } catch (\Throwable $e) {
            $r->erros++;
            $r->log(sprintf('[erro] pasta_id=%d (renomear no Drive): %s', $pastaId, $e->getMessage()));
        }
    }

    /**
     * Via sistema→Drive (pasta): se a Pasta ainda não tem drive_folder_id, cria a pasta no Drive
     * (nome pela convenção) e grava o vínculo. No-op se já vinculada. flush()+clear() atômico.
     */
    public function garantirFolderDaPasta(int $pastaId, string $rootFolderId, GoogleDriveClientInterface $client, bool $dryRun, ResultadoReconciliacaoPasta $r): void
    {
        $conn = $this->em->getConnection();
        $folderAtual = $conn->fetchOne('SELECT drive_folder_id FROM pasta WHERE id = :id', ['id' => $pastaId]);
        if ($folderAtual === false || ($folderAtual !== null && $folderAtual !== '')) {
            return; // pasta inexistente ou já vinculada
        }

        if ($dryRun) {
            $r->criadasNoDrive++;

            return;
        }

        $pasta = $this->em->find(Pasta::class, $pastaId);
        if ($pasta === null) {
            return;
        }
        try {
            $folderId = $client->criarPasta(
                self::nomeEsperado($pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao()),
                $rootFolderId,
            );
            $pasta->setDriveFolderId($folderId);
            $pasta->setDriveSyncedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->em->clear();
            $r->criadasNoDrive++;
        } catch (\Throwable $e) {
            $r->erros++;
            $r->log(sprintf('[erro] pasta_id=%d (sistema→Drive): %s', $pastaId, $e->getMessage()));
            if (!$this->em->isOpen()) {
                $r->fatal = true;
            }
        }
    }

    /**
     * Reconcilia os arquivos de UMA pasta vinculada, nas duas vias. Trabalha por dados ESCALARES
     * (ids, caminhos) e faz flush()+clear() por ITEM: a mutação no Drive e a gravação do vínculo
     * ficam atômicas, então uma falha de item nunca descarta o vínculo já feito nem re-duplica na
     * re-execução (D5/R1). Ordem sistema→Drive antes de Drive→sistema: o recém-enviado (id em
     * $conhecidos) não é reimportado. Identidade sempre por drive_file_id. Marca $r->fatal se o EM fechar.
     */
    public function reconciliarArquivosDaPasta(
        int $pastaId,
        GoogleDriveClientInterface $client,
        bool $dryRun,
        ResultadoReconciliacaoPasta $r,
        ModoSincronizacao $modo = ModoSincronizacao::Enviar,
    ): void {
        $conn = $this->em->getConnection();

        $row = $conn->fetchAssociative('SELECT tenant_id, drive_folder_id FROM pasta WHERE id = :id', ['id' => $pastaId]);
        if ($row === false || $row['drive_folder_id'] === null) {
            return;
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
        $subsRaw = $client->listarSubpastas($caseFolder);
        /** @var array<string, string> $subpastasDoCaso */
        $subpastasDoCaso = [];
        foreach ($subsRaw as $sub) {
            $subpastasDoCaso[mb_strtoupper($sub['nome'])] = $sub['id'];
        }

        // --- Via A — sistema→Drive: cada doc sem drive_file_id sobe (seção vira subpasta-espelho, Fork 1). ---
        $docRows = $modo->envia() ? $conn->fetchAllAssociative(
            'SELECT id, caminho_arquivo FROM pasta_documento WHERE pasta_id = :p AND drive_file_id IS NULL ORDER BY id ASC',
            ['p' => $pastaId],
        ) : [];
        foreach ($docRows as $docRow) {
            $caminho = $this->storage->caminho($this->uploadsDir, (string) $docRow['caminho_arquivo']);
            // Checagem read-only, faz sentido no dry-run também (preview fiel ao lote real, §10.6/passo 4).
            if (!$this->storage->existe($caminho)) {
                $r->erros++;
                $r->log(sprintf('[erro] doc_id=%d: arquivo físico ausente (%s)', (int) $docRow['id'], (string) $docRow['caminho_arquivo']));

                continue;
            }
            if ($dryRun) {
                $r->arquivosEnviados++;

                continue;
            }
            $doc = $this->em->find(PastaDocumento::class, (int) $docRow['id']);
            if ($doc === null) {
                continue;
            }
            try {
                $alvo   = $this->resolverPastaAlvoNoDrive($doc->getSecao(), $caseFolder, $subpastasDoCaso, $client);
                $fileId = $client->enviarArquivo($alvo, $doc->getNomeOriginal(), $caminho, $doc->getMimeType());
                // Marca como conhecido ANTES do flush: o arquivo JÁ está no Drive; se o flush do vínculo
                // falhar, a Via B (mesma rodada) não pode reimportá-lo como novo (evita duplicar o doc).
                $conhecidos[$fileId] = true;
                $doc->setDriveFileId($fileId);
                $this->em->flush();
                $this->em->clear();
                $r->arquivosEnviados++;
            } catch (\Throwable $e) {
                $r->erros++;
                $r->log(sprintf('[erro] doc_id=%d (sistema→Drive): %s', (int) $docRow['id'], $e->getMessage()));
                if (!$this->em->isOpen()) {
                    $r->fatal = true;

                    return;
                }
                $this->em->clear();
            }
        }

        // --- Via B — Drive→sistema (recursivo, §11.6). ---
        // R2: fora do modo Importar esta via NÃO roda. O código segue inteiro de propósito — é o
        // motor do botão "Importar" (Fatia B) e da migração do acervo (R7). O que mudou foi só
        // quem pode acioná-lo: nunca o cron nem o worker, só um pedido explícito.
        if (!$modo->importa()) {
            return;
        }

        // Seção resolvida por nome-UPPER e criada preguiçosamente (só no 1º arquivo importável → sem
        // seção fantasma). O id memoizado sobrevive aos clears por item.
        /** @var array<string, int> $secaoIds */
        $secaoIds = [];

        // Arquivos na raiz do caso → sem seção.
        foreach ($client->listarArquivos($caseFolder) as $arq) {
            if (!$this->baixarArquivo($arq, null, $secaoIds, $pastaId, $tenantId, $conhecidos, $dryRun, $client, $r)) {
                return;
            }
        }
        // Subpastas de 1º nível: tudo abaixo é achatado para a seção daquela subpasta (seção-avó).
        foreach ($subsRaw as $sub) {
            $nomeUP = mb_strtoupper($sub['nome']);
            foreach ($this->coletarArquivosRecursivo($sub['id'], $client) as $arq) {
                if (!$this->baixarArquivo($arq, $nomeUP, $secaoIds, $pastaId, $tenantId, $conhecidos, $dryRun, $client, $r)) {
                    return;
                }
            }
        }
    }

    /**
     * Resolve a pasta-alvo no Drive para um doc que sobe: raiz do caso se sem seção; senão a
     * subpasta-espelho da seção (find-or-create por nome — Fork 1). Atualiza o mapa de subpastas.
     *
     * @param array<string, string> $subpastasDoCaso
     */
    private function resolverPastaAlvoNoDrive(?PastaSecao $secao, string $caseFolder, array &$subpastasDoCaso, GoogleDriveClientInterface $client): string
    {
        if ($secao === null) {
            return $caseFolder;
        }
        $nomeUP = $secao->getNome(); // já UPPER
        if (isset($subpastasDoCaso[$nomeUP])) {
            return $subpastasDoCaso[$nomeUP];
        }
        $novo = $client->criarPasta($nomeUP, $caseFolder);
        $subpastasDoCaso[$nomeUP] = $novo;

        return $novo;
    }

    /**
     * Todos os arquivos de $folderId e de qualquer subpasta abaixo (achatamento §11.6).
     *
     * @return list<array{id: string, nome: string, tamanho: int, mimeType: string}>
     */
    private function coletarArquivosRecursivo(string $folderId, GoogleDriveClientInterface $client): array
    {
        $arquivos = $client->listarArquivos($folderId);
        foreach ($client->listarSubpastas($folderId) as $sub) {
            foreach ($this->coletarArquivosRecursivo($sub['id'], $client) as $arq) {
                $arquivos[] = $arq;
            }
        }

        return $arquivos;
    }

    /**
     * Acha (por nome, na pasta) ou cria a PastaSecao e retorna seu id, com flush+clear próprio — o id
     * sobrevive aos clears dos downloads seguintes. Memoiza em $secaoIds.
     *
     * @param array<string, int> $secaoIds
     */
    private function resolverSecaoId(string $nomeUP, array &$secaoIds, int $pastaId, int $tenantId, ResultadoReconciliacaoPasta $r): ?int
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
            $r->secoesArquivos++;

            return $id;
        } catch (\Throwable $e) {
            $r->log(sprintf('[erro] seção "%s" (pasta_id=%d): %s', $nomeUP, $pastaId, $e->getMessage()));
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
     * @param array<string, int>  $secaoIds
     * @param array<string, true> $conhecidos
     * @return bool false se o EM fechou (fatal)
     */
    private function baixarArquivo(array $arq, ?string $secaoNomeUP, array &$secaoIds, int $pastaId, int $tenantId, array &$conhecidos, bool $dryRun, GoogleDriveClientInterface $client, ResultadoReconciliacaoPasta $r): bool
    {
        // Google-native (Docs/Sheets/…): não têm conteúdo binário para baixar → pula.
        if (str_starts_with($arq['mimeType'], 'application/vnd.google-apps.')) {
            $r->googleNative++;

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
            $r->ignoradosDuplicados++;

            return true;
        }
        // Nome não cabe em titulo/nome_original (varchar 255) → pula e reporta (não envenena o insert).
        if (mb_strlen($arq['nome']) > 255) {
            $r->ignoradosNome++;
            $r->log(sprintf('[ignorado] nome > 255 caracteres (drive_file_id=%s) — pulado', $arq['id']));

            return true;
        }
        // tamanho_bytes é INT4 no schema: acima do teto não cabe → pula e reporta.
        if ($arq['tamanho'] > self::TAMANHO_MAX_INT4) {
            $r->ignoradosTamanho++;
            $r->log(sprintf('[ignorado] "%s" (%d bytes) acima do limite da coluna de tamanho — pulado', $arq['nome'], $arq['tamanho']));

            return true;
        }
        if ($dryRun) {
            $r->arquivosBaixados++;

            return true;
        }

        // Seção materializada preguiçosamente (só agora, para um arquivo que SERÁ importado).
        $secaoId = null;
        if ($secaoNomeUP !== null) {
            $secaoId = $this->resolverSecaoId($secaoNomeUP, $secaoIds, $pastaId, $tenantId, $r);
            if ($secaoId === null) {
                if (!$this->em->isOpen()) {
                    $r->fatal = true;

                    return false;
                }
                $r->erros++;

                return true;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jusprime-sync-');
        if ($tmp === false) {
            $r->erros++;
            $r->log(sprintf('[erro] drive_file_id=%s: falha ao criar arquivo temporário', $arq['id']));

            return true;
        }
        $nomeStorage = null;
        try {
            // Fork 2 / D8: o client baixa em streaming direto para o $tmp (sem carregar o arquivo
            // inteiro em memória) e aqui o $tmp é MOVIDO para o storage, também sem reler o conteúdo.
            $client->baixarArquivo($arq['id'], $tmp);
            $extensao = pathinfo($arq['nome'], PATHINFO_EXTENSION);
            if ($extensao === '') {
                $extensao = 'bin';
            }
            $nomeStorage = $this->storage->moverParaArmazenamento($tmp, $this->uploadsDir, $extensao);

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
            $r->arquivosBaixados++;
        } catch (\Throwable $e) {
            $r->erros++;
            $r->log(sprintf('[erro] drive_file_id=%s (Drive→sistema): %s', $arq['id'], $e->getMessage()));
            // Remove o arquivo já gravado no storage cujo doc não persistiu (evita órfão em disco).
            if ($nomeStorage !== null) {
                $this->storage->excluir($this->storage->caminho($this->uploadsDir, $nomeStorage));
            }
            if (!$this->em->isOpen()) {
                $r->fatal = true;

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
