<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ArquivoStorageInterface
{
    public function salvar(UploadedFile $arquivo, string $diretorio): string;

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string;

    /**
     * Move um arquivo já existente em disco para o armazenamento, sem reler o conteúdo em memória.
     * Ideal para arquivos grandes (ex.: download em streaming do Drive → storage). Retorna o nome
     * único gerado. O arquivo de origem deixa de existir no caminho original em caso de sucesso.
     */
    public function moverParaArmazenamento(string $caminhoOrigem, string $diretorio, string $extensao): string;

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse;

    public function excluir(string $caminhoCompleto): void;

    public function existe(string $caminhoCompleto): bool;

    public function caminho(string $diretorio, string $nomeArquivo): string;
}
