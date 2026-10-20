<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\EquipeParaOcultarAbaOutput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;

/**
 * A lista do modal "Ocultar aba" (spec pasta-ocultar-aba §2.4): quem pode ter a aba escondida neste
 * escritório, com "função · setor" e a marca de quem a regra atual já esconde.
 *
 * A guarda (pasta do escritório, quem configura) é do controller: esta leitura só monta a lista.
 */
final class ListarEquipeParaOcultarAbaUseCase
{
    public function __construct(
        private readonly AbaOcultaDaPastaRepository $regras,
        private readonly EquipeQuePodeTerAbaOculta $equipe,
    ) {
    }

    public function executar(Pasta $pasta, AbaDaPasta $aba, Tenant $tenant): EquipeParaOcultarAbaOutput
    {
        return EquipeParaOcultarAbaOutput::montar(
            $aba,
            $this->equipe->doEscritorio($tenant),
            $this->regras->daAba($pasta, $aba, $tenant),
        );
    }
}
