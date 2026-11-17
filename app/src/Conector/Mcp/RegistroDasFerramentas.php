<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use Mcp\Server\Builder;

/**
 * Quem põe as ferramentas do conector no servidor MCP do SDK (`docs/specs/mcp-remoto-oauth.md`, 6 e 6.2, regra 1). O
 * servidor do `/mcp` chama {@see registrarNo()} ao montar o `Builder`; a implementação registra só as ferramentas do
 * catálogo fechado, cada uma com título, anotações e `_meta` do catálogo, atrás do despacho único.
 */
interface RegistroDasFerramentas
{
    public function registrarNo(Builder $construtor): void;
}
