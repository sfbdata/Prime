<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;

/**
 * As análises de UM agente numa pasta (mais recente primeiro, sem excluídas) e o que o botão
 * "Gerar análise" do agente precisa saber: se há uma em andamento (botão travado) e se já houve
 * uma concluída ("Gerar nova análise").
 */
final readonly class AnalisesDoAgenteOutput
{
    /**
     * @param list<AnaliseOutput> $analises
     */
    public function __construct(
        public Agente $agente,
        public array $analises,
        public ?AnaliseOutput $ultimaConcluida,
    ) {
    }

    public static function vazio(Agente $agente): self
    {
        return new self($agente, [], null);
    }

    public function temAnalise(): bool
    {
        return $this->analises !== [];
    }

    public function temEmAndamento(): bool
    {
        foreach ($this->analises as $analise) {
            if ($analise->emAndamento) {
                return true;
            }
        }

        return false;
    }

    public function total(): int
    {
        return count($this->analises);
    }
}
