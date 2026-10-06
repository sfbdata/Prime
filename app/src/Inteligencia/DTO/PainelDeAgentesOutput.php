<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;

/**
 * O painel do drawer: os sete agentes, cada um com as suas análises nesta pasta. Todo agente tem
 * entrada — sem análise, uma saída vazia — para o template percorrer `Agente::cases()` sem `??`.
 */
final readonly class PainelDeAgentesOutput
{
    /**
     * @param array<string, AnalisesDoAgenteOutput> $porAgente chave = `Agente->value`
     */
    public function __construct(
        public array $porAgente,
    ) {
    }

    public function de(Agente $agente): AnalisesDoAgenteOutput
    {
        return $this->porAgente[$agente->value] ?? AnalisesDoAgenteOutput::vazio($agente);
    }

    public function temEmAndamento(): bool
    {
        foreach ($this->porAgente as $saida) {
            if ($saida->temEmAndamento()) {
                return true;
            }
        }

        return false;
    }

    public function totalDeAnalises(): int
    {
        $total = 0;
        foreach ($this->porAgente as $saida) {
            $total += $saida->total();
        }

        return $total;
    }
}
