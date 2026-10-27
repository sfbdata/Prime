<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\OrdemDosModelos;
use App\Pasta\Enum\TipoDePeca;

/**
 * O que a lista do banco de modelos pede: matéria (ou só favoritos), tipo, busca e ordem. Valor
 * desconhecido vira "sem filtro", nunca erro: a lista é leitura e a tela não deve quebrar por um
 * parâmetro velho na URL.
 */
final readonly class FiltroDosModelosInput
{
    public function __construct(
        public ?MateriaDeModelo $materia = null,
        public ?TipoDePeca $tipo = null,
        public string $busca = '',
        public OrdemDosModelos $ordem = OrdemDosModelos::MaisUsados,
        public bool $soFavoritos = false,
    ) {
    }

    /** `$materia` aceita '' (todas), '★' (só favoritos) ou o valor de uma matéria. */
    public static function daRequisicao(?string $materia, ?string $tipo, ?string $busca, ?string $ordem): self
    {
        $soFavoritos = $materia === '★';

        return new self(
            materia: $soFavoritos ? null : MateriaDeModelo::tryFrom((string) $materia),
            tipo: TipoDePeca::tryFrom((string) $tipo),
            busca: trim((string) $busca),
            ordem: OrdemDosModelos::daRequisicao($ordem),
            soFavoritos: $soFavoritos,
        );
    }
}
