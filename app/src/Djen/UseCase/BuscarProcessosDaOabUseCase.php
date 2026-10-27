<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\DTO\BuscaDeProcessosDaOabOutput;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\Exception\AcompanhamentoInvalidoException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\ProcessosDaOabRepository;
use App\Entity\Tenant\Tenant;

/**
 * "Buscar processos da OAB" (desenho 11): puxa do DJEN o histórico das OABs ativas do escritório
 * (os processos da aba nascem das publicações) e devolve quantos processos a aba passa a ter.
 */
final class BuscarProcessosDaOabUseCase
{
    /** Janela do histórico consultado a cada busca. */
    public const DIAS_DE_HISTORICO = 90;

    public function __construct(
        private readonly OabMonitoradaRepository $oabRepository,
        private readonly SincronizarPublicacoesDjenUseCase $sincronizar,
        private readonly ProcessosDaOabRepository $processosDaOab,
    ) {
    }

    public function executar(Tenant $tenant, \DateTimeImmutable $hoje): BuscaDeProcessosDaOabOutput
    {
        $ativas = $this->oabRepository->listarAtivasDoTenant($tenant);
        if ($ativas === []) {
            throw new AcompanhamentoInvalidoException('Cadastre ao menos uma OAB para buscar os processos.');
        }

        $resultado = $this->sincronizar->executar($tenant, PeriodoConsulta::ultimosDias(self::DIAS_DE_HISTORICO, $hoje), false, false);
        $processos = \count($this->processosDaOab->acompanhados($tenant)) + \count($this->processosDaOab->relatorio($tenant));

        return new BuscaDeProcessosDaOabOutput(
            $processos,
            array_map(static fn ($o): string => $o->getNumero() . '/' . $o->getUf(), $ativas),
            $resultado,
        );
    }
}
