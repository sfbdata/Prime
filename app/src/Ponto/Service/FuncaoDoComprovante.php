<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;

/**
 * Se o comprovante da batida vale agora (E15, P48, P95 e P96): a função `ponto-comprovante` nas duas camadas (o Master
 * para todos e o escritório, em `FuncoesDoPonto`; chave sem registro vale ligada) E o selo do HMAC pronto. Sem o selo,
 * nada aparece e nada é emitido, mesmo com a função ligada (seção 10 da spec).
 */
final class FuncaoDoComprovante
{
    public const RECURSO = FuncoesDoPonto::COMPROVANTE;

    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly FuncoesDoPonto $funcoes,
        private readonly SeloDoComprovante $selo,
    ) {
    }

    /** A batida emite o comprovante: a função ligada no Master e no escritório, e o selo pronto. */
    public function emite(Tenant $escritorio): bool
    {
        return $this->selo->disponivel() && $this->funcoes->vale(self::RECURSO, $escritorio);
    }

    /**
     * A aba Comprovantes e o "Validar código" aparecem: o selo pronto, a função visível no Master (o Master vê a função
     * desligada, como nas outras chaves) e ligada no escritório (desenho L447 e L757: o escritório desligado esconde a aba).
     */
    public function abaVisivel(Tenant $escritorio): bool
    {
        return $this->selo->disponivel()
            && $this->recursos->visivel(self::RECURSO)
            && $this->funcoes->ligadaNoEscritorio(self::RECURSO, $escritorio);
    }
}
