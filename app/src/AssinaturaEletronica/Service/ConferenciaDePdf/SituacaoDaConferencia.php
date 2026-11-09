<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** Veredito do arquivo inteiro (contrato da etapa 5, seção 3.4). */
enum SituacaoDaConferencia: string
{
    case Ilegivel = 'ilegivel';
    case SemAssinatura = 'sem_assinatura';
    case Integro = 'integro';
    case Alterado = 'alterado';
    case NaoConferido = 'nao_conferido';
}
