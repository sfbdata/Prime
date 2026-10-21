<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de adicionar o endereço de uma subseção (POST /master/seccionais/{uf}/subsecoes, JSON
 * `{nome, endereco, telefone?}`), vindo da janela da OAB. `doJson()` só apara: texto vazio vira nulo. Obrigatório
 * e tamanho são regras do {@see \App\Master\UseCase\AdicionarSubsecaoNaSeccionalUseCase}.
 */
final readonly class AdicionarSubsecaoNaSeccionalInput
{
    public function __construct(
        /** Quem adiciona: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public ?string $nome = null,
        public ?string $endereco = null,
        public ?string $telefone = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        return new self($ator, $uf, self::texto($corpo['nome'] ?? null), self::texto($corpo['endereco'] ?? null), self::texto($corpo['telefone'] ?? null));
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
