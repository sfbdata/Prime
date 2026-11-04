<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Pasta\Entity\PastaDocumento;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reconhecimento do Drive, parte dos arquivos (spec `docs/specs/drive-reconhecimento.md`): depois que uma pasta do
 * sistema é ligada a uma pasta do Drive que já existia, cada documento dela sem vínculo é ligado ao arquivo do Drive
 * de mesmo nome original (sem diferença de caixa) e mesmo tamanho em bytes, procurado na pasta e nas subpastas dela.
 * Assim o envio automático não sobe de novo o que já está lá.
 *
 * Só lê o Drive: nada é criado, apagado ou renomeado. O vínculo do arquivo é único no sistema inteiro: arquivo do
 * Drive já ligado a outro documento é pulado. Não faz flush (quem chama decide a transação).
 */
final class LigadorDeArquivosDoDrive
{
    /** Até onde descer nas subpastas do Drive (pasta do caso = nível 0). */
    public const PROFUNDIDADE_MAXIMA = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return int quantos documentos foram ligados */
    public function ligarDocumentosDaPasta(int $pastaId, string $driveFolderId, GoogleDriveClientInterface $client): int
    {
        $documentos = $this->documentosSemVinculo($pastaId);
        if ($documentos === []) {
            return 0;
        }

        $porChave = $this->arquivosPorChave($this->coletarArquivos($driveFolderId, $client, 0));
        $ligados = 0;
        foreach ($documentos as $doc) {
            $fileId = $this->proximoArquivoLivre($porChave, self::chave((string) $doc['nome_original'], (int) $doc['tamanho_bytes']));
            if ($fileId === null) {
                continue;
            }
            $this->em->find(PastaDocumento::class, (int) $doc['id'])?->setDriveFileId($fileId);
            ++$ligados;
        }

        return $ligados;
    }

    /** @return list<array<string, mixed>> */
    private function documentosSemVinculo(int $pastaId): array
    {
        // SQL cru não passa pelo LixeiraFilter: o `excluido_em IS NULL` é explícito.
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, nome_original, tamanho_bytes FROM pasta_documento WHERE pasta_id = :p AND drive_file_id IS NULL AND excluido_em IS NULL ORDER BY id ASC',
            ['p' => $pastaId],
        );
    }

    /**
     * @return list<array{id: string, nome: string, tamanho: int, mimeType: string}>
     */
    private function coletarArquivos(string $folderId, GoogleDriveClientInterface $client, int $nivel): array
    {
        $arquivos = $client->listarArquivos($folderId);
        if ($nivel >= self::PROFUNDIDADE_MAXIMA) {
            return $arquivos;
        }
        foreach ($client->listarSubpastas($folderId) as $sub) {
            foreach ($this->coletarArquivos($sub['id'], $client, $nivel + 1) as $arquivo) {
                $arquivos[] = $arquivo;
            }
        }

        return $arquivos;
    }

    /**
     * @param list<array{id: string, nome: string, tamanho: int, mimeType: string}> $arquivos
     *
     * @return array<string, list<string>> chave (nome+tamanho) => ids dos arquivos do Drive, na ordem listada
     */
    private function arquivosPorChave(array $arquivos): array
    {
        $porChave = [];
        foreach ($arquivos as $arquivo) {
            $porChave[self::chave($arquivo['nome'], $arquivo['tamanho'])][] = $arquivo['id'];
        }

        return $porChave;
    }

    /**
     * Tira da fila o primeiro arquivo com a chave que ainda não está ligado a documento nenhum do sistema.
     *
     * @param array<string, list<string>> $porChave
     */
    private function proximoArquivoLivre(array &$porChave, string $chave): ?string
    {
        while (($porChave[$chave] ?? []) !== []) {
            $fileId = array_shift($porChave[$chave]);
            if (!$this->arquivoJaLigado($fileId)) {
                return $fileId;
            }
        }

        return null;
    }

    private function arquivoJaLigado(string $fileId): bool
    {
        $noBanco = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE drive_file_id = :id', ['id' => $fileId]) > 0;
        if ($noBanco) {
            return true;
        }
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[PastaDocumento::class] ?? [] as $doc) {
            if ($doc instanceof PastaDocumento && $doc->getDriveFileId() === $fileId) {
                return true;
            }
        }

        return false;
    }

    private static function chave(string $nome, int $tamanho): string
    {
        return mb_strtolower(trim($nome)) . "\0" . $tamanho;
    }
}
