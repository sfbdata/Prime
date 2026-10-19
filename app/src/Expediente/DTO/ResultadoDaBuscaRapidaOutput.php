<?php

declare(strict_types=1);

namespace App\Expediente\DTO;

/**
 * Um item da lista da Busca rápida (desenho 02 EXPEDIENTES L405-421): ícone pelo grupo, título,
 * subtítulo e o grupo em maiúsculas à direita. A URL de abrir fica com o controller.
 *
 * `restrito` existe desde já para a regra do AcessoAPasta (spec docs/specs/pasta-cadeado.md, D1):
 * pasta Oculta para a pessoa aparece só com número, cliente e CPF e o cadeado. Hoje é sempre false,
 * porque a regra provisória (BuscarRapidoUseCase::filtrarVisiveis) só deixa passar o que a pessoa vê.
 */
final class ResultadoDaBuscaRapidaOutput
{
    public function __construct(
        public readonly string $grupo,
        public readonly string $titulo,
        public readonly string $subtitulo,
        public readonly int $pastaId,
        public readonly bool $trancada,
        public readonly bool $restrito = false,
    ) {
    }
}
