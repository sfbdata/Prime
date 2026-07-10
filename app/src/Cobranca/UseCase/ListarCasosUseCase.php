<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Entity\Tenant\Tenant;

/**
 * Leitura: lista paginada de casos do tenant, com busca + facetas (status, carteira) e paginação
 * (Etapa 8). Para cada caso da PÁGINA, deriva o saldo exigível/vencido via `CalculadoraSaldo`
 * (invariável 20 — saldo nunca é coluna). Custo limitado à página; se virar gargalo, materializar o
 * saldo é um follow-up de perf. Tenant fixado no servidor; devolve Output DTOs.
 */
final class ListarCasosUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $repository,
        private readonly CalculadoraSaldo $calculadoraSaldo,
    ) {
    }

    /**
     * @param array<string, string> $filtros busca, status, carteira
     * @return array{itens: list<CasoResumoOutput>, total: int}
     */
    public function executar(Tenant $tenant, array $filtros, int $pagina, int $porPagina, string $ordenar, string $direcao): array
    {
        $casos = $this->repository->findByFilters($filtros, $tenant, $pagina, $porPagina, $ordenar, $direcao);

        $itens = [];
        foreach ($casos as $caso) {
            $itens[] = CasoResumoOutput::fromEntity(
                $caso,
                $this->calculadoraSaldo->saldoExigivel($caso),
                $this->calculadoraSaldo->saldoVencido($caso),
            );
        }

        return [
            'itens' => $itens,
            'total' => $this->repository->countByFilters($filtros, $tenant),
        ];
    }
}
