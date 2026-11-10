<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/** A situação do certificado do signatário na LCR ou no OCSP, como o validador de cadeia responde (protocolo `v1`). */
enum RevogacaoDaCadeia: string
{
    case Boa = 'boa';
    case Revogada = 'revogada';
    case Desconhecida = 'desconhecida';
}
