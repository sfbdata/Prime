<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de gravar a nota explicativa de uma seccional (POST /master/seccionais/{uf}/nota, JSON `{texto, cidade?}`),
 * vindo do "Salvar nota" da janela da OAB. `doJson()` só apara: texto vazio vira nulo, e nulo apaga a nota. Sem cidade
 * é a nota do estado ({@see \App\Master\UseCase\SalvarNotaDaSeccionalUseCase}); com cidade, a da cidade
 * ({@see \App\Master\UseCase\SalvarNotaDaCidadeOabUseCase}).
 */
final readonly class SalvarNotaDaSeccionalInput
{
    public function __construct(
        /** Quem grava: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public ?string $texto = null,
        public ?string $cidade = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        $texto = is_string($corpo['texto'] ?? null) ? trim($corpo['texto']) : '';
        $cidade = is_string($corpo['cidade'] ?? null) ? trim($corpo['cidade']) : '';

        return new self($ator, $uf, $texto === '' ? null : $texto, $cidade === '' ? null : $cidade);
    }
}
