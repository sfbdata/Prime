<?php

declare(strict_types=1);

namespace App\Tests\Sync\Support;

use App\Sync\Exception\DownloadDoDriveFalhouException;
use App\Sync\Service\GoogleDriveClientInterface;

/** Double em memória: nenhum acesso à rede. */
final class FakeGoogleDriveClient implements GoogleDriveClientInterface
{
    /** @var array<string, array{nome: string, parent: string}> */
    public array $pastas = [];
    /** @var array<string, array{nome: string, folder: string, tamanho: int, mimeType: string}> */
    public array $arquivos = [];
    private int $seq = 0;

    /** Semear uma subpasta já existente no "Drive" (para testes de listagem). */
    public function seedPasta(string $id, string $nome, string $parent): void
    {
        $this->pastas[$id] = ['nome' => $nome, 'parent' => $parent];
    }

    /** Semear um arquivo já existente no "Drive". */
    public function seedArquivo(string $id, string $nome, string $folder, int $tamanho = 10, string $mimeType = 'application/pdf'): void
    {
        $this->arquivos[$id] = ['nome' => $nome, 'folder' => $folder, 'tamanho' => $tamanho, 'mimeType' => $mimeType];
    }

    public function criarPasta(string $nome, string $parentId): string
    {
        $id = 'folder-' . (++$this->seq);
        $this->pastas[$id] = ['nome' => $nome, 'parent' => $parentId];

        return $id;
    }

    public function listarSubpastas(string $parentId): array
    {
        $out = [];
        foreach ($this->pastas as $id => $p) {
            if ($p['parent'] === $parentId) {
                $out[] = ['id' => $id, 'nome' => $p['nome']];
            }
        }

        return $out;
    }

    public function listarArquivos(string $folderId): array
    {
        $out = [];
        foreach ($this->arquivos as $id => $a) {
            if ($a['folder'] === $folderId) {
                $out[] = ['id' => $id, 'nome' => $a['nome'], 'tamanho' => $a['tamanho'], 'mimeType' => $a['mimeType']];
            }
        }

        return $out;
    }

    public function enviarArquivo(string $folderId, string $nome, string $caminhoLocal, string $mimeType): string
    {
        $id = 'file-' . (++$this->seq);
        $this->arquivos[$id] = ['nome' => $nome, 'folder' => $folderId, 'tamanho' => (int) @filesize($caminhoLocal), 'mimeType' => $mimeType];

        return $id;
    }

    /** @var list<string> Onde cada download foi escrito — para o teste conferir que o temporário não sobrou. */
    public array $destinosDeDownload = [];

    public function baixarArquivo(string $fileId, string $destinoLocal): void
    {
        $this->destinosDeDownload[] = $destinoLocal;
        file_put_contents($destinoLocal, 'conteudo-fake-' . $fileId);
        $this->simularFalhaDeDownload($fileId, $destinoLocal);
    }

    /** @var list<array{folderId: string, nome: string}> Rastro das renomeações, para asserção. */
    public array $renomeacoes = [];

    public function renomearPasta(string $folderId, string $novoNome): void
    {
        if (!isset($this->pastas[$folderId])) {
            throw new \RuntimeException('Pasta inexistente no Drive fake: ' . $folderId);
        }

        $this->pastas[$folderId]['nome'] = $novoNome;
        $this->renomeacoes[] = ['folderId' => $folderId, 'nome' => $novoNome];
    }

    /** @var array<string, int> Quantas tentativas de baixar cada id ainda devem falhar (DT-8). */
    private array $falhasDeDownload = [];

    /** As próximas $vezes tentativas de baixar $fileId falham como o client real falha num 403. */
    public function falharDownload(string $fileId, int $vezes = 1): void
    {
        $this->falhasDeDownload[$fileId] = $vezes;
    }

    /**
     * Pior caso de propósito: o corpo do erro FICA no destino antes da exceção. O client real remove o
     * destino; o chamador não pode depender disso para não gravar lixo.
     */
    private function simularFalhaDeDownload(string $fileId, string $destinoLocal): void
    {
        if (($this->falhasDeDownload[$fileId] ?? 0) === 0) {
            return;
        }
        --$this->falhasDeDownload[$fileId];
        file_put_contents($destinoLocal, '{"error":{"code":403,"message":"The download quota for this file has been exceeded."}}');

        throw DownloadDoDriveFalhouException::respostaInvalida($fileId, 403, 'downloadQuotaExceeded: The download quota for this file has been exceeded.');
    }
}
