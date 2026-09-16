<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Doubles;

use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage mínimo que mexe em disco de verdade — mock não serviria, porque o que se quer provar é
 * que o ARQUIVO deixou de existir.
 */
final class StorageDeDiscoParaTeste implements ArquivoStorageInterface
{
    /**
     * Desde a E2.4A nenhum produtor de anexo grava pela interface antiga — a gravação é da
     * `FonteDeUploadHttp` + `ArmazenamentoDeArquivos`. Chamar isto é regressão.
     */
    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        throw new \LogicException('salvar() da interface antiga não deveria mais ser chamado (E2.4A).');
    }

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
    {
        $nome = bin2hex(random_bytes(8)) . '.' . ltrim($extensao, '.');
        file_put_contents($diretorio . '/' . $nome, $conteudo);

        return $nome;
    }

    public function moverParaArmazenamento(string $caminhoOrigem, string $diretorio, string $extensao): string
    {
        $nome = bin2hex(random_bytes(8)) . '.' . ltrim($extensao, '.');
        rename($caminhoOrigem, $diretorio . '/' . $nome);

        return $nome;
    }

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse
    {
        return new BinaryFileResponse($caminhoCompleto);
    }

    public function excluir(string $caminhoCompleto): void
    {
        if (file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
    }

    public function existe(string $caminhoCompleto): bool
    {
        return file_exists($caminhoCompleto);
    }

    public function caminho(string $diretorio, string $nomeArquivo): string
    {
        return $diretorio . '/' . $nomeArquivo;
    }
}
