<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * Os documentos marcados na lista para uma ação em lote (Lixeira, restaurar, excluir, cor): `{ids}` e, na cor, `cor`.
 */
final readonly class SelecaoDeDocumentos
{
    public const MAXIMO = 2000;

    /**
     * @param list<int> $ids sem repetição
     */
    private function __construct(
        public array $ids,
        public ?CorDaLinha $cor,
    ) {
    }

    /** @throws AcaoRecusada */
    public static function deJson(mixed $json): self
    {
        $ids = \is_array($json) ? ($json['ids'] ?? null) : null;
        if (!\is_array($ids) || $ids === [] || \count($ids) > self::MAXIMO || !array_is_list($ids)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selecao_invalida');
        }

        foreach ($ids as $id) {
            if (!\is_int($id) || $id < 1) {
                throw new AcaoRecusada('assinatura_eletronica.erro.selecao_invalida');
            }
        }

        return new self(array_values(array_unique($ids)), self::cor($json));
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function cor(array $json): ?CorDaLinha
    {
        if (!\array_key_exists('cor', $json)) {
            return null;
        }

        $cor = \is_string($json['cor']) ? CorDaLinha::tryFrom($json['cor']) : null;

        return $cor ?? throw new AcaoRecusada('assinatura_eletronica.erro.cor_invalida');
    }
}
