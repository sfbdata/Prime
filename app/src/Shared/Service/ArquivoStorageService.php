<?php

namespace App\Shared\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serviço de infraestrutura para operações de arquivo em disco.
 *
 * Responsabilidades: salvar, servir e excluir arquivos físicos.
 * Lógica de negócio (validação, categorização, permissões) fica nos domínios.
 */
final class ArquivoStorageService implements ArquivoStorageInterface
{
    public function salvar(UploadedFile $arquivo, string $diretorio): string
    {
        $this->garantirDiretorio($diretorio);

        $nomeUnico = bin2hex(random_bytes(16)) . '.' . ($arquivo->guessExtension() ?? 'bin');
        $arquivo->move($diretorio, $nomeUnico);

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
