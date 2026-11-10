<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** A nova versão começa byte a byte com a anterior (o `prefixo` do protótipo). */
enum ConferenciaDoPrefixo: string
{
    case NaoPedida = 'nao_pedida';
    case Confere = 'confere';
    case NaoConfere = 'nao_confere';

    public function motivo(): string
    {
        return match ($this) {
            self::NaoPedida => 'A versão anterior não foi informada',
            self::Confere => 'A nova versão começa byte a byte com a anterior',
            self::NaoConfere => 'A nova versão não começa com a anterior',
        };
    }
}
