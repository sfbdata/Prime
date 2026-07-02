<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;

final class ListarMinhasDemandasUseCase
{
    public function __construct(
        private readonly PastaRepository $repository,
    ) {}

    /**
     * Lista as demandas (pastas) do próprio usuário, com busca + facetas + paginação,
     * reusando a máquina de filtros do Expediente. O responsável é SEMPRE o usuário
     * logado, fixado aqui no servidor — nunca vem do request (evita IDOR entre colegas).
     *
     * @param array<string, string> $filtros  busca, status, prioridade, data_de, data_ate
     * @return array{pastas: \App\Pasta\Entity\Pasta[], total: int}
     */
    public function executar(
        User $usuario,
        Tenant $tenant,
        array $filtros,
        int $pagina,
        int $porPagina,
        string $ordenar,
        string $direcao,
    ): array {
        $filtros['responsavel'] = (string) $usuario->getId();

        return [
            'pastas' => $this->repository->findByFilters($filtros, $tenant, $pagina, $porPagina, $ordenar, $direcao),
            'total'  => $this->repository->countByFilters($filtros, $tenant),
        ];
    }
}
