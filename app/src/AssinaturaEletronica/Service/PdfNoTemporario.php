<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * Grava os pedaços do PDF num arquivo da {@see AreaTemporariaPrivada}, um por vez, medindo o sha256 no caminho: o PDF
 * inteiro nunca fica na memória. Quem chama libera a área (o arquivo sai junto).
 *
 * O selo (etapa 8, E8-4) manda o PDF inteiro ao validador (até 20 MB) e recebe o selado: {@see tamanho()}, {@see ler()}
 * e {@see regravar()} são para ele, sobre o mesmo arquivo da área.
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
    public static function tamanho(string $caminho): int
    {
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
    public static function ler(string $caminho): string
    {
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
    public static function regravar(string $caminho, string $conteudo): void
    {
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
}
