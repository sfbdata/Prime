<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\CenarioDaAssembleia;

/** Um grupo da tela Perfis: a assembleia (o evento) e a equipe própria dela ("N representantes" / "Sem equipe própria"). */
final readonly class EquipeDaAssembleia
{
    /** @param list<LinhaDaEquipe> $linhas */
    public function __construct(
        public int $assembleiaId,
        public string $titulo,
        public CenarioDaAssembleia $cenario,
        public bool $editavel,
        public array $linhas,
    ) {
    }

    public function temEquipePropria(): bool
    {
        return [] !== $this->linhas;
    }
}
