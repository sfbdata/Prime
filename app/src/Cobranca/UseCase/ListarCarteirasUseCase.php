<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CarteiraResumoOutput;
use App\Cobranca\Repository\CarteiraRepository;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: lista paginada de carteiras do tenant, com busca e faceta de modo (Etapa 8). Reusa a
 * máquina de filtros do Expediente (busca + facetas + paginação); o tenant é fixado no servidor,
 * nunca vem do request. Devolve Output DTOs (nunca entidades cruas ao Twig).
 *
 * @phpstan-type ResultadoCarteiras array{itens: list<CarteiraResumoOutput>, total: int}
 */
final class ListarCarteirasUseCase
{
    public function __construct(
        private readonly CarteiraRepository $repository,
    ) {
    }

    /**
     * @param array<string, string> $filtros busca, modo
     * @return array{itens: list<CarteiraResumoOutput>, total: int}
     */
    public function executar(Tenant $tenant, array $filtros, int $pagina, int $porPagina, string $ordenar, string $direcao): array
    {
        $carteiras = $this->repository->findByFilters($filtros, $tenant, $pagina, $porPagina, $ordenar, $direcao);

        return [
            'itens' => array_map(CarteiraResumoOutput::fromEntity(...), $carteiras),
            'total' => $this->repository->countByFilters($filtros, $tenant),
        ];
    }
}
