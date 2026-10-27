<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de salvar as configurações globais (POST /master/configuracoes, JSON `{valores: {chave: texto}}`).
 * `doJson()` só normaliza o formato; as regras de cada campo moram no CatalogoDeConfiguracoes.
 */
final readonly class SalvarConfiguracaoGlobalInput
{
    /** @param array<mixed> $valores */
    public function __construct(
        /** Quem salva: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public array $valores,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): self
    {
        return new self($ator, is_array($corpo['valores'] ?? null) ? $corpo['valores'] : []);
    }
}
