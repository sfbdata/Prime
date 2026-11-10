<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O aviso da tela de que a fila sem internet enviou batidas (E14, evento "Sincronização" da trilha, dc L438): os
 * identificadores de envio (`idEnvio`) das batidas que a rodada mandou. A pessoa e o escritório são os da sessão; os
 * identificadores só apontam, e o servidor confere cada um contra as batidas dela neste escritório.
 */
final class SincronizacaoDaFilaInput
{
    /** A fila guarda até 72 h de batidas; mais que isto num aviso só não é uma rodada da fila. */
    public const MAXIMO = 100;

    /** @param list<string> $idsEnvio */
    public function __construct(
        public readonly User $quem,
        public readonly Tenant $tenant,
        public readonly array $idsEnvio,
    ) {
    }

    /**
     * Corpo JSON `{idsEnvio: string[]}`, sem repetidos; null quando a lista não vem, vem vazia, passa do máximo ou
     * traz algo que não é um identificador (texto de 1 a 36 caracteres, como o `id_envio` da batida).
     *
     * @param array<mixed> $corpo
     */
    public static function doJson(User $quem, Tenant $tenant, array $corpo): ?self
    {
        $ids = $corpo['idsEnvio'] ?? null;
        if (!\is_array($ids) || !array_is_list($ids) || $ids === [] || \count($ids) > self::MAXIMO) {
            return null;
        }
        foreach ($ids as $id) {
            if (!\is_string($id) || $id === '' || \strlen($id) > 36) {
                return null;
            }
        }

        return new self($quem, $tenant, array_values(array_unique($ids)));
    }
}
