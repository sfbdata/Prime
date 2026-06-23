<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class CompressorArquivo implements CompressorArquivoInterface
{
    private const QUALIDADE_JPEG = 75;
    private const NIVEL_PNG      = 9;

    /** Acima deste número de pixels o re-encode de imagem é pulado (evita esgotar memória). */
    private const MAX_PIXELS_IMAGEM = 50_000_000;

    /** Tempo máximo (s) para o Ghostscript comprimir um PDF. */
    private const TIMEOUT_PDF = 120;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $ghostscriptBin,
    ) {}

    public function comprimir(string $caminhoCompleto, string $mimeType): ResultadoCompressao
    {
        if (!is_file($caminhoCompleto)) {
            return ResultadoCompressao::naoComprimido(0);
        }

        $tamanhoOriginal = (int) filesize($caminhoCompleto);
        $eraAssinado     = $mimeType === 'application/pdf' && $this->pdfEstaAssinado($caminhoCompleto);

        try {
            $temporario = match ($mimeType) {
                'image/jpeg'      => $this->comprimirJpeg($caminhoCompleto),
                'image/png'       => $this->comprimirPng($caminhoCompleto),
                'application/pdf' => $this->comprimirPdf($caminhoCompleto),
                default           => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao comprimir arquivo; mantendo original.', [
                'caminho'  => $caminhoCompleto,
                'mimeType' => $mimeType,
                'erro'     => $e->getMessage(),
            ]);
            $temporario = null;
        }

        if ($temporario === null) {
            return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
        }

        $tamanhoFinal = (int) filesize($temporario);

        // Só vale a pena trocar se o resultado for menor e não-vazio.
        if ($tamanhoFinal > 0 && $tamanhoFinal < $tamanhoOriginal && rename($temporario, $caminhoCompleto)) {
            return new ResultadoCompressao($tamanhoOriginal, $tamanhoFinal, true, $eraAssinado);
        }

        @unlink($temporario);

        return ResultadoCompressao::naoComprimido($tamanhoOriginal, $eraAssinado);
    }

    public function pdfEstaAssinado(string $caminhoCompleto): bool
    {
        if (!is_file($caminhoCompleto)) {
            return false;
        }

        $conteudo = file_get_contents($caminhoCompleto);
        if ($conteudo === false) {
            return false;
        }

        return str_contains($conteudo, '/ByteRange')
            && (
                str_contains($conteudo, '/Sig')
                || str_contains($conteudo, '/Adobe.PPKLite')
            );
    }

    private function comprimirJpeg(string $caminho): ?string
    {
        if ($this->excedeLimiteDePixels($caminho)) {
            return null;
        }

        $imagem = @imagecreatefromjpeg($caminho);
        if ($imagem === false) {
            return null;
        }

        $temporario = $this->caminhoTemporario($caminho);
        $ok         = imagejpeg($imagem, $temporario, self::QUALIDADE_JPEG);
        imagedestroy($imagem);

        return $ok ? $temporario : null;
    }

    private function comprimirPng(string $caminho): ?string
    {
        if ($this->excedeLimiteDePixels($caminho)) {
            return null;
        }

        $imagem = @imagecreatefrompng($caminho);
        if ($imagem === false) {
            return null;
        }

        // Preserva transparência ao reescrever.
        imagealphablending($imagem, false);
        imagesavealpha($imagem, true);

        $temporario = $this->caminhoTemporario($caminho);
        $ok         = imagepng($imagem, $temporario, self::NIVEL_PNG);
        imagedestroy($imagem);

        return $ok ? $temporario : null;
    }

    private function comprimirPdf(string $caminho): ?string
    {
        $temporario = $this->caminhoTemporario($caminho);

        $processo = new Process([
            $this->ghostscriptBin,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/ebook',
            '-dDetectDuplicateImages=true',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-sOutputFile=' . $temporario,
            $caminho,
        ]);
        $processo->setTimeout(self::TIMEOUT_PDF);
        $processo->run();

        if (!$processo->isSuccessful() || !is_file($temporario)) {
            @unlink($temporario);
            $this->logger->warning('Ghostscript não comprimiu o PDF; mantendo original.', [
                'caminho' => $caminho,
                'saida'   => $processo->getErrorOutput(),
            ]);

            return null;
        }

        return $temporario;
    }

    private function excedeLimiteDePixels(string $caminho): bool
    {
        $info = @getimagesize($caminho);
        if ($info === false) {
            return true;
        }

        return ($info[0] * $info[1]) > self::MAX_PIXELS_IMAGEM;
    }

    private function caminhoTemporario(string $caminho): string
    {
        return dirname($caminho) . '/.compress_' . bin2hex(random_bytes(8));
    }
}
