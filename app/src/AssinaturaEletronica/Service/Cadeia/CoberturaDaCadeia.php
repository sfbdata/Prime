<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/** O que a assinatura cobre, como o validador de cadeia responde (protocolo `v1`): o arquivo inteiro, a revisão ou parte. */
enum CoberturaDaCadeia: string
{
    case ArquivoInteiro = 'arquivo_inteiro';
    case RevisaoInteira = 'revisao_inteira';
    case Parcial = 'parcial';
}
