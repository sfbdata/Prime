<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Evento do histórico inexistente ou de outro escritório (guarda multi-tenant). O chamador traduz em
 * 404: para quem pede, evento de outro tenant simplesmente não existe — responder 403 já confirmaria
 * que o id é válido em algum lugar.
 */
final class EventoNaoEncontradoException extends \DomainException
{
    public function __construct(int $eventoId)
    {
        parent::__construct(sprintf('Evento de histórico %d não encontrado.', $eventoId));
    }
}
