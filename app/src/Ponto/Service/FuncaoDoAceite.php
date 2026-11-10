<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;

/**
 * Se o aceite mensal da folha vale agora no escritório (spec ponto-desenho-0811, E16, P22, P95 e P96; decisões 13 e
 * 16): a função `ponto-aceite` nas duas camadas (o Master para todos e o escritório, em {@see FuncoesDoPonto}; chave
 * sem registro vale ligada) E o selo da folha pronto. Sem o selo, o aceite não se liga a uma folha emitida com código,
 * então o "Assinar folha" não aparece, mesmo com a função ligada.
 *
 * Desligada ou sem o selo, só some o "Assinar folha": o aceite já gravado continua no selo da barra e no carimbo do PDF.
 */
final class FuncaoDoAceite
{
    public const RECURSO = FuncoesDoPonto::ACEITE;

    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly SeloDaFolha $selo,
    ) {
    }

    public function vale(Tenant $escritorio): bool
    {
        return $this->selo->disponivel() && $this->funcoes->vale(self::RECURSO, $escritorio);
    }
}
