<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Quem não configura o cadeado (K1: só o Master e o primário ativo) tentou abrir, alterar ou remover o
 * cadeado, ou decidir um pedido de acesso. O controller responde 403.
 */
final class CadeadoRecusadoException extends \RuntimeException
{
    public const MENSAGEM = 'Só o usuário primário pode trancar a pasta.';
}
