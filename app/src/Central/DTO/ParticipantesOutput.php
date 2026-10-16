<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Quem está na conversa agora (participação ativa) e se quem pede administra o grupo. */
final class ParticipantesOutput
{
    /** @param list<ParticipanteOutput> $participantes */
    public function __construct(
        public readonly int $conversaId,
        public readonly bool $grupo,
        public readonly ?string $nome,
        public readonly bool $souAdministrador,
        public readonly array $participantes,
    ) {
    }
}
