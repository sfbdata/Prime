<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class ArquivoStorageService implements ArquivoStorageInterface
{
    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        $this->garantirDiretorio($diretorio);

        $nomeUnico = bin2hex(random_bytes(16)) . '.' . ($arquivo->guessExtension() ?? 'bin');
        $arquivo->move($diretorio, $nomeUnico);

        return $nomeUnico;
    }

    public function salvarConteudo(string $conteudo, string $diretorio, string $extensao): string
    {
        $this->garantirDiretorio($diretorio);

        $nomeUnico = bin2hex(random_bytes(16)) . '.' . ltrim($extensao, '.');
        file_put_contents($diretorio . '/' . $nomeUnico, $conteudo);

        return $nomeUnico;
    }

    public function moverParaArmazenamento(string $caminhoOrigem, string $diretorio, string $extensao): string
    {
        $this->garantirDiretorio($diretorio);

        $nomeUnico = bin2hex(random_bytes(16)) . '.' . ltrim($extensao, '.');
        $destino   = $diretorio . '/' . $nomeUnico;

        // rename() é atômico e não relê o conteúdo, mas falha entre sistemas de arquivos distintos
        // (ex.: /tmp e o volume de uploads em mounts diferentes → EXDEV). Nesse caso, copia e apaga.
        if (@rename($caminhoOrigem, $destino)) {
            return $nomeUnico;
        }

        if (@copy($caminhoOrigem, $destino)) {
            @unlink($caminhoOrigem);

            return $nomeUnico;
        }

        // copy falhou (ex.: disco cheio no meio da cópia): remove o destino parcial p/ não deixar órfão.
        if (is_file($destino)) {
            @unlink($destino);
        }
        $erro = error_get_last();

        throw new \RuntimeException(sprintf(
            'Não foi possível mover o arquivo para %s: %s',
            $destino,
            $erro['message'] ?? 'motivo desconhecido',
        ));
    }

    public function servir(string $caminhoCompleto, string $nomeOriginal, bool $inline = true): BinaryFileResponse
    {
        $disposicao = $inline
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        $response = new BinaryFileResponse($caminhoCompleto);
        $response->setContentDisposition($disposicao, $nomeOriginal);

        return $response;
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

    private function garantirDiretorio(string $diretorio): void
    {
        if (!is_dir($diretorio)) {
            mkdir($diretorio, 0755, true);
        }
    }
}
