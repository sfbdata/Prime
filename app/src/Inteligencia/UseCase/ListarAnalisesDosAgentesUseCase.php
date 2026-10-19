<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
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

    /**
     * Quantas vezes o limite se busca antes do filtro das abas ocultas: sem a folga, análises
     * bloqueadas entre as mais recentes empurrariam para fora as visíveis mais antigas.
     */
    public const FOLGA_DO_FILTRO = 3;

    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    /**
     * `$abasOcultas`: as abas da pasta ocultas para quem lê. Análise que tirou conteúdo de uma delas
     * não entra (regra única `VisibilidadeDaAnaliseDaPasta::legivelCom`).
     *
     * @param list<string> $abasOcultas
     */
    public function executar(Pasta $pasta, Tenant $tenant, array $abasOcultas): PainelDeAgentesOutput
    {
        $saidas = [];
        foreach (Agente::cases() as $agente) {
            $saidas[$agente->value] = $this->executarParaAgente($pasta, $tenant, $agente, $abasOcultas);
        }

        return new PainelDeAgentesOutput($saidas);
    }

    /** @param list<string> $abasOcultas */
    public function executarParaAgente(Pasta $pasta, Tenant $tenant, Agente $agente, array $abasOcultas): AnalisesDoAgenteOutput
    {
        $entidades = $this->analises->listarPorAlvo(
            $tenant,
            AnaliseDeInteligencia::ALVO_PASTA,
            (int) $pasta->getId(),
            self::LIMITE_POR_AGENTE * self::FOLGA_DO_FILTRO,
            TipoDeAnalise::AnalisePasta,
            $agente,
        );

        return $this->saidaDoAgente($agente, \array_slice(array_values(array_filter(
            $entidades,
            static fn (AnaliseDeInteligencia $a): bool => VisibilidadeDaAnaliseDaPasta::legivelCom($a, $abasOcultas),
        )), 0, self::LIMITE_POR_AGENTE));
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
