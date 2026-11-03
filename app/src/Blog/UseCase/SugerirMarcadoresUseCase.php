<?php

declare(strict_types=1);

namespace App\Blog\UseCase;

use App\Blog\Repository\ArtigoDoBlogRepository;

/** Sugestões do campo de marcadores: os já usados nos outros artigos (mais usados primeiro, até 10). */
final class SugerirMarcadoresUseCase
{
    public function __construct(private readonly ArtigoDoBlogRepository $artigos)
    {
    }

    /** @return list<array{marcador: string, usos: int}> */
    public function executar(?string $busca): array
    {
        return $this->artigos->marcadoresUsados($busca);
    }
}
