<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EstadoDoPrazoJuridico;

/** O resultado da análise do prazo da meta (o retorno do `IA_PRAZO` do desenho 02, dc 4026). */
final readonly class AnaliseDoPrazo
{
    public function __construct(
        public string $tipo,
        /** Dias úteis do prazo legal do tipo (0 = sem prazo legal) e a fonte ("CPC, art. 335"). */
        public int $diasUteisLegais,
        public string $fonteLegal,
        public ?\DateTimeImmutable $prazoJuridico,
        /** O prazo jurídico é aproximado (contado de hoje, sem termo inicial): a tela mostra "≈". */
        public bool $aproximado,
        public EstadoDoPrazoJuridico $estado,
        public \DateTimeImmutable $prazoInterno,
        /** Folga em dias úteis entre o prazo interno e o jurídico (null sem prazo jurídico). */
        public ?int $folga,
        public string $prioridade,
        public bool $urgente,
        /** Dias úteis de hoje até o prazo interno. */
        public int $diasAteOInterno,
        /** Dias úteis de hoje até o prazo jurídico e os dias de semana não úteis no meio. */
        public ?int $diasUteisAteOJuridico,
        public int $feriadosNoPeriodo,
        public ?\DateTimeImmutable $termoInicial,
        /** 'ciencia', 'publicacao' ou null. */
        public ?string $origem,
        /** Pela origem informada, a regra calcularia outra data que a informada pela pessoa. */
        public ?\DateTimeImmutable $alternativa,
        /** A explicação em primeira pessoa do desenho ("Contei 15 dias úteis..."). */
        public string $porque,
    ) {
    }
}
