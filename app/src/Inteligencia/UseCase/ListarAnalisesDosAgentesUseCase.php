<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\AnalisesDoAgenteOutput;
use App\Inteligencia\DTO\PainelDeAgentesOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\Entity\Pasta;

/**
 * As análises dos agentes de uma pasta para o drawer: o painel inteiro (as N mais recentes de
 * CADA agente — uma consulta por agente, para um agente com muitas análises não esconder os
 * outros) ou a lista de UM agente (recarga do fragmento depois do polling).
 *
 * Recebe a Pasta já resolvida e conferida pelo chamador (tenant + `canAccessResource`), como o
 * `ListarAnalisesDaPastaUseCase` do Push.
 */
final class ListarAnalisesDosAgentesUseCase
{
    public const LIMITE_POR_AGENTE = 10;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): PainelDeAgentesOutput
    {
        $saidas = [];
        foreach (Agente::cases() as $agente) {
            $saidas[$agente->value] = $this->executarParaAgente($pasta, $tenant, $agente);
        }

        return new PainelDeAgentesOutput($saidas);
    }

    public function executarParaAgente(Pasta $pasta, Tenant $tenant, Agente $agente): AnalisesDoAgenteOutput
    {
        $entidades = $this->analises->listarPorAlvo(
            $tenant,
            AnaliseDeInteligencia::ALVO_PASTA,
            (int) $pasta->getId(),
            self::LIMITE_POR_AGENTE,
            TipoDeAnalise::AnalisePasta,
            $agente,
        );

        return $this->saidaDoAgente($agente, $entidades);
    }

    /** @param list<AnaliseDeInteligencia> $entidades */
    private function saidaDoAgente(Agente $agente, array $entidades): AnalisesDoAgenteOutput
    {
        $saidas = [];
        $ultimaConcluida = null;
        foreach ($entidades as $entidade) {
            $saidas[] = AnaliseOutput::fromEntity($entidade);
            if ($ultimaConcluida === null && $entidade->estaConcluida()) {
                $ultimaConcluida = AnaliseOutput::fromEntity($entidade);
            }
        }

        return new AnalisesDoAgenteOutput($agente, $saidas, $ultimaConcluida);
    }
}
