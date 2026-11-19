<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** A vida de uma inscrição no Desafio. */
enum SituacaoDaParticipacao: string
{
    case AguardandoPagamento = 'aguardando_pagamento';
    case Ativa = 'ativa';
    case DevolucaoPedida = 'devolucao_pedida';
    case Devolvida = 'devolvida';
}
