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
 * As análises dos agentes de uma pasta para o drawer: o painel inteiro (sete agentes numa
 * consulta, agrupada em PHP) ou a lista de UM agente (recarga do fragmento depois do polling).
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
        $entidades = $this->analises->listarPorAlvo(
            $tenant,
            AnaliseDeInteligencia::ALVO_PASTA,
            (int) $pasta->getId(),
            self::LIMITE_POR_AGENTE * count(Agente::cases()),
            TipoDeAnalise::AnalisePasta,
        );

        /** @var array<string, list<AnaliseDeInteligencia>> $porAgente */
        $porAgente = [];
        foreach ($entidades as $entidade) {
            $agente = $entidade->getAgente();
            if ($agente === null) {
                continue; // linha inconsistente: não é de agente nenhum
            }
            $porAgente[$agente->value][] = $entidade;
        }

        $saidas = [];
        foreach (Agente::cases() as $agente) {
            $saidas[$agente->value] = $this->saidaDoAgente($agente, array_slice($porAgente[$agente->value] ?? [], 0, self::LIMITE_POR_AGENTE));
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
