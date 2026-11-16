<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Service\MontadorDoResumoDoResultado;

/**
 * Gestor pede o resumo para divulgar (imagem ou PDF). Outro escritório e rascunho são tratados como inexistentes
 * (AssembleiaNaoEncontradaException); sem pauta encerrada e apurada, OperacaoInvalidaNaAssembleiaException. Não grava nada.
 */
final class GerarResumoDoResultadoUseCase
{
    public function __construct(
        private readonly ObterAssembleiaUseCase $obter,
        private readonly MontarDivulgacaoDaAssembleiaUseCase $divulgacao,
        private readonly MontadorDoResumoDoResultado $montador,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null): ResumoDoResultado
    {
        $divulgacao = $this->divulgacao->executar($tenant, $assembleiaId);
        $assembleia = $this->obter->executar($tenant, $assembleiaId, $agora);

        return $this->montador->montar($assembleia, $divulgacao, $agora ?? new \DateTimeImmutable());
    }
}
