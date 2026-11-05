<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\MontagemDoRelatorioPastasParadas;
use App\Pasta\Service\PastasVisiveisNoRelatorio;
use Psr\Clock\ClockInterface;

/**
 * "Pastas paradas" (desenho 02, `paradasPDF`/`paradasHTML`; spec docs/specs/pastas-paradas.md,
 * "Página e PDF"): a página que o aviso mensal abre.
 *
 * Quem: quem tem o módulo Expediente (a guarda é do controller). O quê: ver, entre as pastas que
 * pode ver, as paradas há mais de 30 dias, com a pré-análise e a sugestão de cada uma.
 *
 * Visibilidade: a mesma do relatório de pastas sem processo ({@see PastasVisiveisNoRelatorio}), menos as
 * pastas Ocultas para o leitor pelo cadeado (spec pasta-cadeado D1), também para quem vê o escritório
 * inteiro; o financeiro de cada pasta, só para quem pode vê-lo.
 *
 * Só leitura: nada é gravado.
 */
final class GerarRelatorioPastasParadasUseCase
{
    public function __construct(
        private readonly MontagemDoRelatorioPastasParadas $montagem,
        private readonly PastasVisiveisNoRelatorio $visiveis,
        private readonly ClockInterface $clock,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    public function executar(Tenant $tenant, User $leitor): RelatorioPastasParadasOutput
    {
        // Cadeado (spec pasta-cadeado D1): Controladoria e `resources.pasta.view` não passam do cadeado.
        $ocultas = $this->acessoAPasta->idsOcultosPara($leitor, (int) $tenant->getId());

        return $this->montagem->montar($tenant, $this->visiveis->ids($leitor, $tenant), $leitor, $this->clock->now(), $ocultas);
    }
}
