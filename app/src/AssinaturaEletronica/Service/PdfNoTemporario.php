<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * O PDF final num arquivo da {@see AreaTemporariaPrivada}. Quem chama libera a área (o arquivo sai junto).
 *
 * - A geração ({@see gravar()}): os pedaços, um por vez, medindo o sha256 no caminho; o PDF inteiro nunca fica na
 *   memória.
 * - O selo (etapa 8, E8-4) manda o PDF inteiro ao validador e recebe o selado: {@see tamanho()}, {@see ler()} (até
 *   20 MB na memória, o teto do validador) e {@see regravar()}, só sobre um arquivo comum direto na área, como o que o
 *   {@see gravar()} cria.
 */
final class PdfNoTemporario
{
    private function __construct()
    {
    }

    /**
     * @param iterable<string> $pedacos
     *
     * @return array{string, string} o caminho do arquivo e o sha256 dele, em hexadecimal minúsculo
     *
     * @throws FalhaNoTemporario
     */
    public static function gravar(AreaTemporariaPrivada $area, iterable $pedacos): array
    {
        $caminho = $area->caminho() . '/' . bin2hex(random_bytes(8)) . '.pdf';
        $arquivo = @fopen($caminho, 'xb');
        if ($arquivo === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível criar o PDF temporário %s.', $caminho));
        }

        $sha = hash_init('sha256');
        try {
            foreach ($pedacos as $pedaco) {
                if (@fwrite($arquivo, $pedaco) !== \strlen($pedaco)) {
                    throw new FalhaNoTemporario(sprintf('Não foi possível gravar o PDF temporário %s.', $caminho));
                }
                hash_update($sha, $pedaco);
            }
        } finally {
            fclose($arquivo);
        }

        return [$caminho, hash_final($sha)];
    }

    /**
     * O tamanho do arquivo, medido agora (sem o cache do `stat`).
     *
     * @throws FalhaNoTemporario
     */
    public static function tamanho(AreaTemporariaPrivada $area, string $caminho): int
    {
        self::exigirDaArea($area, $caminho);
        clearstatcache(true, $caminho);
        $tamanho = @filesize($caminho);
        if ($tamanho === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível medir o PDF temporário %s.', $caminho));
        }

        return $tamanho;
    }

    /**
     * O arquivo inteiro na memória.
     *
     * @throws FalhaNoTemporario
     */
    public static function ler(AreaTemporariaPrivada $area, string $caminho): string
    {
        self::exigirDaArea($area, $caminho);
        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível abrir o PDF temporário %s.', $caminho));
        }

        try {
            $conteudo = @stream_get_contents($arquivo);
        } finally {
            fclose($arquivo);
        }
        if ($conteudo === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível ler o PDF temporário %s.', $caminho));
        }

        return $conteudo;
    }

    /**
     * Troca o conteúdo do arquivo por este, no mesmo caminho.
     *
     * @throws FalhaNoTemporario
     */
    public static function regravar(AreaTemporariaPrivada $area, string $caminho, string $conteudo): void
    {
        self::exigirDaArea($area, $caminho);
        $arquivo = @fopen($caminho, 'wb');
        if ($arquivo === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível reabrir o PDF temporário %s.', $caminho));
        }

        try {
            $gravados = @fwrite($arquivo, $conteudo);
        } finally {
            fclose($arquivo);
        }
        if ($gravados !== \strlen($conteudo)) {
            throw new FalhaNoTemporario(sprintf('Não foi possível regravar o PDF temporário %s.', $caminho));
        }
    }

    /**
     * Só um arquivo comum direto na área (como o que o {@see gravar()} cria): nem subdiretório, nem `..`, nem link.
     *
     * @throws FalhaNoTemporario
     */
    private static function exigirDaArea(AreaTemporariaPrivada $area, string $caminho): void
    {
        if (\dirname($caminho) !== $area->caminho() || is_link($caminho) || !is_file($caminho)) {
            throw new FalhaNoTemporario(sprintf('O PDF temporário %s não é um arquivo desta área.', $caminho));
        }
    }
}
