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
