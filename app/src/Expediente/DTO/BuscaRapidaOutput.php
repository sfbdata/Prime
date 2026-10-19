<?php

declare(strict_types=1);

namespace App\Expediente\DTO;

/**
 * Resposta da Busca rápida: até 8 resultados e se a pessoa abre pasta trancada direto.
 *
 * `abreTrancada` repete a regra da Lista do Expediente (expediente-lista.js, `abrirOuAvisar`): quem não
 * é administrador do escritório, ao abrir pasta trancada, recebe o aviso de sigilo em vez da pasta.
 */
final class BuscaRapidaOutput
{
    /**
     * @param list<ResultadoDaBuscaRapidaOutput> $resultados
     */
    public function __construct(
        public readonly array $resultados,
        public readonly bool $abreTrancada,
    ) {
    }
}
