<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Tenant\Tenant;

/** A conta depois de suspensa ou reativada: o painel troca a situação da linha sem recarregar. */
final readonly class ContaAlteradaOutput
{
    public const ATIVA = 'Ativa';
    public const SUSPENSA = 'Suspensa';

    public function __construct(
        public int $id,
        public string $nome,
        public string $situacao,
    ) {
    }

    public static function fromEntity(Tenant $conta): self
    {
        return new self((int) $conta->getId(), (string) $conta->getName(), $conta->isActive() === true ? self::ATIVA : self::SUSPENSA);
    }
}
