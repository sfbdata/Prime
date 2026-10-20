<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Quem está agindo no Contribua: a pessoa, o escritório da sessão e os dois papéis que mudam o que a
 * página mostra (o Master vê tudo e atende sugestões; só o primário pede espaço, como no desenho).
 */
final readonly class Solicitante
{
    public function __construct(
        public User $usuario,
        public Tenant $escritorio,
        public bool $ehMaster,
        public bool $ehPrimario,
    ) {
    }

    public function tenantId(): int
    {
        return (int) $this->escritorio->getId();
    }

    /** Conta Nº do desenho: o id do escritório com 6 dígitos. */
    public function numeroDaConta(): string
    {
        return str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT);
    }

    public function nome(): string
    {
        return (string) ($this->usuario->getFullName() ?: $this->usuario->getEmail());
    }
}
