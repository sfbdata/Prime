<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AbaOcultaResultadoOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use Psr\Clock\ClockInterface;

/**
 * "Ocultar para toda a equipe" no botão direito da aba (spec pasta-ocultar-aba §2.1, desenho L.4415).
 *
 * Quem: o Master ou um primário do escritório. Efeito: a aba some, NESTA pasta, para todos os
 * demais, inclusive quem entrar na equipe depois. A regra anterior (lista de pessoas) é substituída.
 */
final class OcultarAbaParaTodosUseCase
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws AbaOcultaRecusadaException */
    public function executar(Pasta $pasta, AbaDaPasta $aba, User $quem, Tenant $tenant): AbaOcultaResultadoOutput
    {
        $this->visibilidade->garantirQuePodeConfigurar($pasta, $quem, $tenant);

        $agora = $this->relogio->now();
        $regra = $this->regras->daAba($pasta, $aba, $tenant);
        if ($regra === null) {
            $regra = AbaOcultaDaPasta::paraTodaAEquipe($tenant, $pasta, $aba, $quem, $agora);
        } else {
            $regra->ocultarParaTodos($quem, $agora);
        }
        $this->regras->salvar($regra, flush: true);

        return AbaOcultaResultadoOutput::ocultaParaTodos($aba);
    }
}
