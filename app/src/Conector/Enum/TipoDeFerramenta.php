<?php

declare(strict_types=1);

namespace App\Conector\Enum;

use App\Conector\Service\ServidorDeAutorizacao;

/**
 * O tipo de cada ferramenta do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 6.4, 6.6 e 6.7). Decide o escopo
 * que a ferramenta exige (5.1) e as anotações MCP (6.2, regra 8). Leitura e gravação nunca na mesma ferramenta (6).
 */
enum TipoDeFerramenta: string
{
    case Leitura = 'leitura';
    case Gravacao = 'gravacao';
    case Confirmacao = 'confirmacao';

    /** O escopo OAuth que a ferramenta exige: `mcp:leitura` só para leitura; gravação e confirmação, `mcp:escrita`. */
    public function escopo(): string
    {
        return $this === self::Leitura ? ServidorDeAutorizacao::ESCOPO_LEITURA : ServidorDeAutorizacao::ESCOPO_ESCRITA;
    }

    public function eLeitura(): bool
    {
        return $this === self::Leitura;
    }
}
