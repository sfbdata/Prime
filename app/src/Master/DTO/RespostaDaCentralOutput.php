<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Entity\CentralMasterResposta;

/** Uma resposta do histórico ("Você respondeu" quando foi este Master; senão, o nome de quem respondeu). */
final class RespostaDaCentralOutput
{
    public function __construct(
        public readonly string $texto,
        public readonly \DateTimeImmutable $em,
        public readonly string $porNome,
        public readonly bool $minha,
        public readonly string $canal,
    ) {
    }

    public static function daResposta(CentralMasterResposta $r, User $eu): self
    {
        $minha = $r->getPor() !== null && $r->getPor()->getId() === $eu->getId();

        return new self($r->getTexto(), $r->getEm(), $r->getPorNome(), $minha, $r->getCanal());
    }

    /** @return array<string, mixed> */
    public function emJson(): array
    {
        return ['texto' => $this->texto, 'em' => $this->em->format(\DateTimeInterface::ATOM), 'porNome' => $this->porNome, 'minha' => $this->minha, 'canal' => $this->canal];
    }
}
