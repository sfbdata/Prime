<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Participação de uma pauta para a caixa "Acompanhar" da cédula: quantas unidades já votaram e quantas são aptas.
 * Só contagens. Nunca traz voto por opção nem nome de quem votou (sigilo enquanto a pauta está aberta).
 */
final readonly class ParticipacaoDaPautaOutput
{
    public function __construct(
        /** aguardando, aberta, suspensa, encerrada ou cancelada. */
        public string $estado,
        public int $votaram,
        public int $aptas,
    ) {
    }

    /** Percentual inteiro de 0 a 100 de quem já votou entre as aptas. */
    public function percentual(): int
    {
        return $this->aptas <= 0 ? 0 : min(100, intdiv($this->votaram * 100, $this->aptas));
    }

    /** O estado já não muda sozinho: a página pode parar de perguntar. */
    public function terminou(): bool
    {
        return \in_array($this->estado, ['encerrada', 'cancelada'], true);
    }

    /** @return array{estado: string, votaram: int, aptas: int, percentual: int, terminou: bool} */
    public function paraJson(): array
    {
        return ['estado' => $this->estado, 'votaram' => $this->votaram, 'aptas' => $this->aptas, 'percentual' => $this->percentual(), 'terminou' => $this->terminou()];
    }
}
