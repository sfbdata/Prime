<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** Veredito de uma assinatura, com os valores do `status` do protótipo. */
enum VereditoDaAssinatura: string
{
    case Integra = 'integra';
    case Alterada = 'alterada';
    case NaoConferida = 'naoverif';
}
