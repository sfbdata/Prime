<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Enum\CategoriaDeIncidente;
use App\Master\Enum\GravidadeDeIncidente;

/**
 * Abrir incidente (POST /master/desempenho/incidentes, JSON). `doJson()` devolve nulo quando falta o título ou a
 * categoria/gravidade não são do catálogo; título longo é cortado no tamanho da coluna.
 */
final readonly class AbrirIncidenteInput
{
    public function __construct(
        public ?User $ator,
        public string $titulo,
        public CategoriaDeIncidente $categoria,
        public GravidadeDeIncidente $gravidade,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): ?self
    {
        $titulo = is_string($corpo['titulo'] ?? null) ? trim($corpo['titulo']) : '';
        $categoria = CategoriaDeIncidente::tryFrom(is_string($corpo['categoria'] ?? null) ? $corpo['categoria'] : '');
        $gravidade = GravidadeDeIncidente::tryFrom(is_string($corpo['gravidade'] ?? null) ? $corpo['gravidade'] : '');
        if ($titulo === '' || $categoria === null || $gravidade === null) {
            return null;
        }

        return new self($ator, mb_substr($titulo, 0, IncidenteDeDesempenho::TITULO_MAXIMO), $categoria, $gravidade);
    }
}
