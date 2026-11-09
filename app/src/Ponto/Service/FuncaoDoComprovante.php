<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Master\Service\RecursosDoSistema;

/**
 * Se o comprovante da batida vale agora (E15, P48 e P96): a função `ponto-comprovante` do Master (nasce ligada: chave
 * sem linha vale ligada) E o selo do HMAC pronto. Sem o selo, nada aparece e nada é emitido, mesmo com a função
 * ligada (seção 10 da spec).
 */
final class FuncaoDoComprovante
{
    public const RECURSO = 'ponto-comprovante';

    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly SeloDoComprovante $selo,
    ) {
    }

    /** A batida emite o comprovante: a função ligada para todos e o selo pronto. */
    public function emite(): bool
    {
        return $this->selo->disponivel() && $this->recursos->estaAtivo(self::RECURSO);
    }

    /** A aba Comprovantes e o "Validar código" aparecem (o Master vê a função desligada, como nas outras chaves). */
    public function abaVisivel(): bool
    {
        return $this->selo->disponivel() && $this->recursos->visivel(self::RECURSO);
    }
}
