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

    /**
     * Renomeia a pasta $folderId no Drive (spec fase2 §12.5/D12.4 — requisito R3).
     *
     * O sistema é a fonte: quando o número/cliente/ação da pasta muda aqui, o Drive tem de
     * acompanhar. Até 2026-08 isto não existia — renomear no sistema NÃO mexia no Drive, e a
     * divergência só era REPORTADA pelo reconciliador, nunca corrigida.
     *
     * Chamar apenas quando o nome realmente mudou: renomear é WRITE e pesa mais na cota da
     * API do que uma leitura. Varrer as 1070 pastas renomeando todas de hora em hora seria
     * desperdício e risco de rate limit (D12.3).
     */
    public function renomearPasta(string $folderId, string $novoNome): void;
}
