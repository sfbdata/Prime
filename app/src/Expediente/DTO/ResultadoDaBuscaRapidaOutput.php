<?php

declare(strict_types=1);

namespace App\Expediente\DTO;

/**
 * Um item da lista da Busca rápida (desenho 02 EXPEDIENTES L405-421): ícone pelo grupo, título,
 * subtítulo e o grupo em maiúsculas à direita. A URL de abrir fica com o controller.
 *
 * `restrito`: a pasta está OCULTA pelo cadeado para quem busca (spec pasta-cadeado D1). Aparece só com
 * número, cliente e CPF, com o cadeado, e ao abrir cai na tela "Pasta trancada" (com o pedido de acesso).
 */
final class ResultadoDaBuscaRapidaOutput
{
    public function __construct(
        public readonly string $grupo,
        public readonly string $titulo,
        public readonly string $subtitulo,
        public readonly int $pastaId,
        public readonly string $nup,
        public readonly bool $restrito,
    ) {
    }
}
