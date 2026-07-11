<?php

declare(strict_types=1);

namespace App\Tests\Sync\Support;

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

    public function baixarArquivo(string $fileId, string $destinoLocal): void
    {
        file_put_contents($destinoLocal, 'conteudo-fake-' . $fileId);
    }
}
