<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbaOcultaResultadoOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;

/**
 * "Mostrar para todos" (spec pasta-ocultar-aba §2.3, desenho L.4417): apaga a regra da aba nesta
 * pasta. Sem regra, nada a fazer, e a resposta é a mesma (a aba já é visível para todos).
 */
final class MostrarAbaParaTodosUseCase
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
    ) {
    }

    /** @throws AbaOcultaRecusadaException */
    public function executar(Pasta $pasta, AbaDaPasta $aba, User $quem, Tenant $tenant): AbaOcultaResultadoOutput
    {
        $this->visibilidade->garantirQuePodeConfigurar($pasta, $quem, $tenant);

        $this->regras->comTravaDaPasta($pasta, function () use ($pasta, $aba, $tenant): void {
            $regra = $this->regras->daAba($pasta, $aba, $tenant);
            if ($regra !== null) {
                $this->regras->remover($regra, flush: true);
            }
        });

        return AbaOcultaResultadoOutput::visivelParaTodos($aba);
    }
}
