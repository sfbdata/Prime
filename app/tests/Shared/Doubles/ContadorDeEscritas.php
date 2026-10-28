<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

/**
 * Filtro de escrita que conta quantos `fwrite` chegam a um stream: é como o teste prova que o
 * download do espelho grava bloco a bloco, sem juntar o corpo inteiro numa string antes.
 */
final class ContadorDeEscritas extends \php_user_filter
{
    private const NOME = 'jusprime_contador_de_escritas';

    public static int $escritas = 0;

    /** Um stream temporário com o contador zerado e ligado na escrita. @return resource */
    public static function streamContado(): mixed
    {
        if (!\in_array(self::NOME, stream_get_filters(), true)) {
            stream_filter_register(self::NOME, self::class);
        }
        self::$escritas = 0;

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Não foi possível abrir o stream temporário.');
        }
        stream_filter_append($stream, self::NOME, \STREAM_FILTER_WRITE);

        return $stream;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            ++self::$escritas;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return \PSFS_PASS_ON;
    }
}
