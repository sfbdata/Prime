<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * Um escritório da pessoa na tela de autorização do conector (`docs/specs/mcp-remoto-oauth.md`, 4 e 14.a). O que não
 * pode ser conectado aparece desabilitado, com o motivo em texto simples (o mesmo da recusa do portão ou da liberação).
 */
final class EscritorioParaConectarOutput
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $disponivel,
        public readonly ?string $motivo,
    ) {
    }
}
