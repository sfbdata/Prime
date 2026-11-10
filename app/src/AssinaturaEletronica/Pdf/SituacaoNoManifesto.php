<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/** A situação do signatário no cartão do manifesto e a cor da barra e da linha de situação (dc:418). */
enum SituacaoNoManifesto
{
    case Assinado;
    case Recusado;
    case Pendente;

    public function cor(): Cor
    {
        return Cor::hex(match ($this) {
            self::Assinado => '#1e7b45',
            self::Recusado => '#a3232b',
            self::Pendente => '#7a5200',
        });
    }
}
