<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de mudança nas Metas de divulgação (POST /master/metas-divulgacao, JSON `{acao, ...}`). `doJson()` só
 * normaliza o formato; as regras (intervalos, UF, cor, frequência) moram na entidade.
 *
 * Ações: ajustar {mercado, valor = o alvo}, alvo {valor}, automatica {ligado}, frequencia {texto}, fase {ligado},
 * atualizar_agora, voltar_automatico, meta_uf {uf, valor|null}, meta_cidade {uf, cidade, valor|null},
 * destaque {uf, texto|null}.
 */
final readonly class AlterarMetaDeDivulgacaoInput
{
    public function __construct(
        /** Quem altera: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $acao,
        public ?int $mercado = null,
        public ?int $valor = null,
        public ?bool $ligado = null,
        public ?string $texto = null,
        public ?string $uf = null,
        public ?string $cidade = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): self
    {
        return new self(
            $ator,
            is_string($corpo['acao'] ?? null) ? $corpo['acao'] : '',
            self::inteiro($corpo['mercado'] ?? null),
            self::inteiro($corpo['valor'] ?? null),
            is_bool($corpo['ligado'] ?? null) ? $corpo['ligado'] : null,
            is_string($corpo['texto'] ?? null) && $corpo['texto'] !== '' ? $corpo['texto'] : null,
            is_string($corpo['uf'] ?? null) ? $corpo['uf'] : null,
            is_string($corpo['cidade'] ?? null) ? $corpo['cidade'] : null,
        );
    }

    /** Número inteiro de até 10 dígitos, em número ou texto; qualquer outra coisa vira nulo (a entidade recusa). */
    private static function inteiro(mixed $v): ?int
    {
        return is_int($v) ? $v : (is_string($v) && preg_match('/^\d{1,10}$/', $v) ? (int) $v : null);
    }
}
