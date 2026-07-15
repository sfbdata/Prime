<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Desfecho de um contato de cobrança (ajuste 2026-07). O default operacional é "Não atendido" (o caso
 * típico da tentativa). Backed string — gravado no payload `dados` do EventoHistorico; apresentação via
 * `label()`. Sem persistência em coluna tipada → não há migração.
 */
enum ResultadoContato: string
{
    case NaoAtendido = 'nao_atendido';
    case CaixaPostal = 'caixa_postal';
    case NumeroErrado = 'numero_errado';
    case PrometeuPagar = 'prometeu_pagar';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::NaoAtendido => 'Não atendido',
            self::CaixaPostal => 'Caixa postal',
            self::NumeroErrado => 'Número errado',
            self::PrometeuPagar => 'Prometeu pagar',
            self::Outro => 'Outro',
        };
    }
}
