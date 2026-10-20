<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Comando;

/**
 * As análises de UM comando (ou relatório) do drawer numa pasta, mais recente primeiro, sem
 * excluídas, com as antigas do agente legado ({@see Comando::doAgenteLegado}); e o que o botão do
 * comando precisa saber: se há uma em andamento (botão travado) e se já houve uma concluída
 * ("Gerar nova análise").
 */
final readonly class AnalisesDoComandoOutput
{
    /**
     * @param list<AnaliseOutput> $analises
     */
    public function __construct(
        public Comando $comando,
        public array $analises,
        public ?AnaliseOutput $ultimaConcluida,
    ) {
    }

    public static function vazio(Comando $comando): self
    {
        return new self($comando, [], null);
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
