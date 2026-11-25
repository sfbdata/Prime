<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

/**
 * Uma página da tabela "Quem indica" do Painel do Master: as pessoas (mais envios primeiro), o total que casa com a
 * busca (para a paginação) e a busca que valeu (vazia quando o termo tinha menos de 2 letras).
 */
final class QuemIndicaOutput
{
    /** @param list<IndicadorOutput> $indicadores */
    public function __construct(
        public readonly array $indicadores,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina,
        public readonly string $busca,
    ) {
    }
}
