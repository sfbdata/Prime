<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;

/** Uma linha do histórico de status: de qual para qual, quem mudou e quando. */
final readonly class MudancaDeStatusDaQuestaoOutput
{
    public function __construct(
        public StatusDaQuestaoJuridica $de,
        public StatusDaQuestaoJuridica $para,
        public string $por,
        public \DateTimeImmutable $em,
    ) {
    }

    public static function daLinha(MudancaDeStatusDaQuestaoJuridica $m): self
    {
        return new self($m->getDe(), $m->getPara(), $m->getMudadaPorNome(), $m->getMudadaEm());
    }
}
