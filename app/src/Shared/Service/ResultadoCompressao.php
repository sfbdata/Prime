<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Resultado de uma tentativa de compressão de arquivo.
 *
 * Quando o arquivo não foi comprimido (tipo não suportado, falha best-effort ou
 * resultado maior que o original), `comprimido` é false e `tamanhoFinal` é igual
 * a `tamanhoOriginal`.
 */
final readonly class ResultadoCompressao
{
    public function __construct(
        public int $tamanhoOriginal,
        public int $tamanhoFinal,
        public bool $comprimido,
        public bool $eraAssinado = false,
    ) {}

    public static function naoComprimido(int $tamanho, bool $eraAssinado = false): self
    {
        return new self($tamanho, $tamanho, false, $eraAssinado);
    }
}
