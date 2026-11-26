<?php

declare(strict_types=1);

namespace App\Blog\DTO;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\SituacaoDoArtigo;

/** O artigo depois de Agendar ou Cancelar agendamento (a data é a de Brasília). */
final readonly class ArtigoAgendadoOutput
{
    public function __construct(
        public int $id,
        public string $situacao,
        public string $situacaoRotulo,
        public ?string $publicarEm,
        public ?string $publicarEmRotulo,
    ) {
    }

    public static function de(ArtigoDoBlog $artigo): self
    {
        $agendado = $artigo->getSituacao() === SituacaoDoArtigo::Agendado ? $artigo->getPublicarEm() : null;

        return new self(
            id: (int) $artigo->getId(),
            situacao: $artigo->getSituacao()->value,
            situacaoRotulo: $artigo->getSituacao()->rotulo(),
            publicarEm: $agendado?->format('Y-m-d\TH:i'),
            publicarEmRotulo: $agendado?->format('d/m/Y H:i'),
        );
    }
}
