<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * As assembleias do escritório, mais recentes primeiro, como o painel da Gestão eleitoral, Acompanhar e Resultados
 * mostram. Antes de montar, grava o encerramento das pautas com prazo vencido. Com `$pessoa`, esconde a assembleia que a
 * equipe própria deixa `Oculto` para ela (teto da equipe; os KPIs, que saem desta lista, também não a contam).
 */
final class ObterPainelDoVotoUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
        private readonly AcessoAAssembleia $acesso,
    ) {
    }

    /** @return list<AssembleiaNoPainel> */
    public function executar(Tenant $tenant, ?\DateTimeImmutable $agora = null, ?User $pessoa = null): array
    {
        $lidas = [];
        $doEscritorio = $this->assembleias->listarDoEscritorio($tenant);
        foreach ($doEscritorio as $assembleia) {
            $this->encerrarVencidas->executar($assembleia, $agora);
        }
        foreach (null === $pessoa ? $doEscritorio : $this->acesso->visiveis($pessoa, $doEscritorio) as $assembleia) {
            $lidas[] = $this->leitura->montar($assembleia);
        }

        return $lidas;
    }
}
