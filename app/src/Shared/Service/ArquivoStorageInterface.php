<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface ArquivoStorageInterface
{
    public function salvar(UploadedFile $arquivo, string $diretorio): string;

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string;

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse;

    public function excluir(string $caminhoCompleto): void;

    public function existe(string $caminhoCompleto): bool;

    public function caminho(string $diretorio, string $nomeArquivo): string;
}
