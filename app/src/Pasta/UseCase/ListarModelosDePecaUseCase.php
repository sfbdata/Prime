<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\FiltroDosModelosInput;
use App\Pasta\Enum\BlocoDeMateria;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Repository\MateriaFavoritaDeModeloRepository;
use App\Pasta\Repository\ModeloDePecaFavoritoRepository;
use App\Pasta\Repository\ModeloDePecaRepository;

/**
 * Tudo que a lista do banco de modelos precisa numa leitura só: as linhas filtradas, o total sem
 * filtro (cabeçalho e chip "Todas"), a contagem por matéria e por bloco, as estrelas do usuário.
 *
 * Os contadores ignoram busca e tipo, como no desenho 02. Zero não vira "0": a tela mostra vazio.
 */
final class ListarModelosDePecaUseCase
{
    public function __construct(
        private readonly ModeloDePecaRepository $modeloRepository,
        private readonly ModeloDePecaFavoritoRepository $favoritoRepository,
        private readonly MateriaFavoritaDeModeloRepository $materiaFavoritaRepository,
    ) {
    }

    /**
     * @return array{
     *   linhas: list<array{id: int, titulo: string, materia: MateriaDeModelo, tipo: \App\Pasta\Enum\TipoDePeca, usos: int, atualizadoEm: \DateTimeImmutable, favorito: bool}>,
     *   total: int,
     *   totalFavoritos: int,
     *   porMateria: array<string, int>,
     *   porBloco: array<string, int>,
     *   materiasFavoritas: list<MateriaDeModelo>
     * }
     */
    public function executar(Tenant $tenant, User $usuario, FiltroDosModelosInput $filtro): array
    {
        $idsFavoritos = $this->favoritoRepository->idsDoUsuario($usuario, $tenant);
        $linhas       = array_map(
            static fn (array $l): array => $l + ['favorito' => in_array($l['id'], $idsFavoritos, true)],
            $this->modeloRepository->listar($tenant, $filtro, $usuario),
        );

        $porMateria = $this->modeloRepository->contarPorMateria($tenant);
        $porBloco   = [];
        foreach (BlocoDeMateria::cases() as $bloco) {
            $porBloco[$bloco->value] = array_sum(array_map(
                static fn (MateriaDeModelo $m): int => $porMateria[$m->value] ?? 0,
                $bloco->materias(),
            ));
        }

        return [
            'linhas'            => $linhas,
            'total'             => array_sum($porMateria),
            'totalFavoritos'    => count($idsFavoritos),
            'porMateria'        => $porMateria,
            'porBloco'          => $porBloco,
            'materiasFavoritas' => $this->materiaFavoritaRepository->materiasDoUsuario($usuario, $tenant),
        ];
    }
}
