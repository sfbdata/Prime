<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * De onde veio cada versão guardada do PDF de um documento (contrato da etapa 5, seção 2, item 1):
 *
 * - `original`: o PDF enviado no rascunho que já chegou com assinaturas digitais ("documento já assinado"), guardado
 *   byte a byte;
 * - `para_assinar`: o PDF que o BlueJus gerou para o primeiro signatário gov.br ou ICP de documento sem assinatura
 *   prévia, congelado na primeira geração (E5-1): o PDF devolvido tem de começar com ele;
 * - `devolvida`: o PDF que um signatário gov.br ou ICP assinou e devolveu.
 */
enum OrigemDaVersao: string
{
    case Original = 'original';
    case ParaAssinar = 'para_assinar';
    case Devolvida = 'devolvida';
}
