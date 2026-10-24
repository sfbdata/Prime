<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Uma pendência de registro da própria pessoa (spec `ponto-esquecimento-nao-trava.md` R5): o dia com registro
 * incompleto, o escritório e a batida que falta. Só a própria pessoa vê esta lista, então o nome do escritório pode ir.
 */
final class PendenciaDeRegistroOutput
{
    /**
     * @param string $dia AAAA-MM-DD
     * @param string $tipo a batida que falta: entrada, repouso, retorno ou saida
     * @param bool $atual o escritório da sessão: o pedido de esquecimento sai daqui; nos outros, a pessoa troca antes
     */
    public function __construct(
        public readonly string $dia,
        public readonly string $tipo,
        public readonly int $escritorioId,
        public readonly string $escritorio,
        public readonly bool $atual,
    ) {
    }
}
