<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/**
 * Recusa no fluxo de exclusão da conta pelo primário (PR 5). A mensagem é para a tela; `status` diz ao
 * controller o código HTTP (403 sem permissão, 409 estado, 422 dado).
 */
final class PedidoDeExclusaoRecusado extends \DomainException
{
    public function __construct(string $mensagem, public readonly int $status = 422)
    {
        parent::__construct($mensagem);
    }

    public static function semPermissao(): self
    {
        return new self('Só o usuário primário pode pedir a exclusão da conta.', 403);
    }
}
