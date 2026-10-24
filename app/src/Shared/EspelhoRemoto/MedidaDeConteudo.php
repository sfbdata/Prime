<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * SHA-256 e tamanho de um arquivo do armazenamento, medidos na MESMA leitura (revisão da P2.3, I3).
 *
 * Medir o tamanho numa leitura e o SHA em outra deixa uma janela em que o arquivo muda entre as duas,
 * e a linha da outbox nasce com um par que não existe. Uma leitura só, em blocos, sem carregar em
 * memória.
 */
final readonly class MedidaDeConteudo
{
    private function __construct(
        public string $sha256,
        public int $tamanhoBytes,
    ) {
    }

    public static function de(ArmazenamentoDeArquivos $armazenamento, ChaveDeArquivo $chave): self
    {
        $stream = $armazenamento->abrir($chave);

        try {
            $contexto = hash_init('sha256');
            $lidos    = hash_update_stream($contexto, $stream);

            return new self(hash_final($contexto), $lidos);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
