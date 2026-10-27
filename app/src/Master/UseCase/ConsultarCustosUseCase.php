<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\DTO\PlanoDoCatalogo;
use App\Contribua\Service\CatalogoDePlanos;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Master\Service\ApuracaoDeCustos;
use Psr\Clock\ClockInterface;

/**
 * Dados da seção "Custos" do Painel do Master (desenho 05 Perfis 1.2, L260-270 e L917-937; spec
 * docs/specs/master-custos.md). Só leitura. O mês vem como `AAAA-MM`; qualquer outra coisa cai no mês atual.
 * As contas e os planos vêm dos mesmos casos de uso que alimentam as outras seções do painel.
 */
final class ConsultarCustosUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly ConsultarContasUseCase $contas,
        private readonly CatalogoDePlanos $catalogo,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(?string $mes = null): array
    {
        $mes = $mes !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 ? $mes : $this->clock->now()->format('Y-m');

        return ApuracaoDeCustos::apurar(
            $mes,
            $this->lancamentos->doMes($mes),
            $this->contas->executar(),
            array_map(static fn (PlanoDoCatalogo $p): array => [
                'codigo' => $p->codigo,
                'nome' => $p->nome,
                'cotaGb' => $p->cotaGb,
                'precoCentavos' => $p->precoCentavos,
                'ativo' => $p->ativo,
            ], $this->catalogo->todos()),
        );
    }
}
