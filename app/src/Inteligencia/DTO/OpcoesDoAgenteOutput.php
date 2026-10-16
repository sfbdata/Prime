<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O vocabulário do escritório para o agente traduzir "a Mariana" ou "Despachar" em id — o equivalente
 * no servidor do `BJPastasPonte.resps()/marcadores()` do protótipo.
 */
final class OpcoesDoAgenteOutput
{
    /**
     * @param list<array{id: int, nome: string}>                               $responsaveis
     * @param list<array{id: int, nome: string, cor: ?string, paiId: ?int}>    $marcadores
     * @param list<array{valor: string, rotulo: string}>                       $situacoes
     * @param list<array{valor: string, rotulo: string}>                       $prioridades
     */
    public function __construct(
        public readonly array $responsaveis,
        public readonly array $marcadores,
        public readonly array $situacoes,
        public readonly array $prioridades,
    ) {
    }
}
