<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * Grava os pedaços do PDF num arquivo da {@see AreaTemporariaPrivada}, um por vez, medindo o sha256 no caminho: o PDF
 * inteiro nunca fica na memória. Quem chama libera a área (o arquivo sai junto).
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
}
