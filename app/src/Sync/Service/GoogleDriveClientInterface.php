<?php

declare(strict_types=1);

namespace App\Sync\Service;

interface GoogleDriveClientInterface
{
    /** Cria uma pasta filha de $parentId no Shared Drive. Retorna o ID da pasta criada. */
    public function criarPasta(string $nome, string $parentId): string;

    /**
     * Lista as subpastas imediatas de $parentId.
     * @return list<array{id: string, nome: string}>
     */
    public function listarSubpastas(string $parentId): array;

    /**
     * Lista os arquivos (não-pastas) diretos de $folderId.
     * @return list<array{id: string, nome: string, tamanho: int, mimeType: string}>
     */
    public function listarArquivos(string $folderId): array;

    /** Envia um arquivo local para $folderId. Retorna o ID do arquivo criado. */
    public function enviarArquivo(string $folderId, string $nome, string $caminhoLocal, string $mimeType): string;

    /** Baixa o arquivo $fileId para $destinoLocal. */
    public function baixarArquivo(string $fileId, string $destinoLocal): void;
}
