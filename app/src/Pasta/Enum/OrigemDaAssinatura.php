<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/**
 * De onde veio o selo "Assinado" de um documento da pasta.
 *
 * - `Digital`: o próprio arquivo traz a assinatura (`.p7s`/`.p7m`, ou PDF com dicionário de
 *   assinatura PAdES/ICP-Brasil/gov.br), detectada no upload. Não se desmarca pela tela: o
 *   arquivo continua assinado.
 * - `Manual`: alguém do escritório marcou pelo ⋮ (assinado à caneta e escaneado). Quem marcou
 *   pode desmarcar.
 */
enum OrigemDaAssinatura: string
{
    case Digital = 'digital';
    case Manual = 'manual';
}
