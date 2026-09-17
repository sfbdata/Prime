<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * A imagem cabe na memória que sobra? (E2.6A, D28)
 *
 * O GD decodifica para uma matriz de 4 bytes por pixel, e essa memória CONTA no `memory_limit` (o
 * GD é o embutido). Estourar o limite é **Fatal error**, não exceção: nenhum `catch` roda, nenhum
 * `finally` limpa, o worker morre e o upload fica pela metade. Isto é o que impede o Fatal.
 *
 * Medido no container (limite de 128M): 30 milhões de pixels decodificam; 7000x7000 (49 milhões,
 * abaixo do teto antigo de 50 milhões) mata o processo com "Allowed memory size exhausted". É o
 * caso concreto de uma digitalização A4 a 600 dpi ou de um PNG de poucos KB com dimensões enormes,
 * que qualquer usuário logado pode enviar.
 *
 * O que sobra é o limite menos o que já está alocado — numa requisição real o Symfony já ocupa a
 * parte dele, e o teto fixo ignorava isso.
 */
final class OrcamentoDeMemoriaDeImagem
{
    /** Medido: a matriz do GD custa 4 bytes por pixel. */
    private const BYTES_POR_PIXEL = 4;

    /** Ponteiros de linha, buffers do codificador e a cópia que o `imagepng()` monta. */
    private const FOLGA = 1.3;

    /** O que fica de pé para o resto da requisição (Doctrine, Twig, a resposta). */
    private const RESERVA_BYTES = 8 * 1024 * 1024;

    public static function cabe(int $largura, int $altura, ?int $limiteBytes = null, ?int $emUsoBytes = null): bool
    {
        if ($largura < 1 || $altura < 1) {
            return false;
        }

        $limite = $limiteBytes ?? self::limiteDoProcesso();
        if ($limite < 0) {
            return true; // `memory_limit = -1`: quem manda é a máquina, não o PHP
        }

        $disponivel = $limite - ($emUsoBytes ?? memory_get_usage(true)) - self::RESERVA_BYTES;

        return $disponivel > 0
            && ($largura * $altura * self::BYTES_POR_PIXEL) * self::FOLGA <= $disponivel;
    }

    /**
     * O `memory_limit` em bytes; negativo quando não há limite.
     *
     * @param string|null $valor o que o php.ini diz; null lê do processo. O parâmetro existe porque
     *                           `ini_set()` RECUSA baixar o limite abaixo do que já está em uso — um
     *                           teste que tentasse ajustar o ambiente passaria sozinho e falharia
     *                           dentro da suíte completa, que gasta mais memória
     */
    public static function limiteDoProcesso(?string $valor = null): int
    {
        $bruto = trim($valor ?? (string) ini_get('memory_limit'));
        if ($bruto === '' || $bruto === '-1') {
            return -1;
        }

        $numero = (int) $bruto;
        if ($numero < 0) {
            return -1;
        }

        return match (strtolower(substr($bruto, -1))) {
            'g'     => $numero * 1024 * 1024 * 1024,
            'm'     => $numero * 1024 * 1024,
            'k'     => $numero * 1024,
            default => $numero,
        };
    }
}
