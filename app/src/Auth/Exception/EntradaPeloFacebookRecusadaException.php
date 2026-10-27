<?php

declare(strict_types=1);

namespace App\Auth\Exception;

/** O "Continuar com Facebook" não deu: a mensagem é a que a tela de entrada mostra à pessoa. */
final class EntradaPeloFacebookRecusadaException extends \RuntimeException
{
    public const SEM_CONTA = 'Não encontramos conta ligada a este Facebook. Crie sua conta e escritório.';
    public const FALHOU = 'Não foi possível continuar com o Facebook. Tente de novo.';
    public const CANCELADA = 'O acesso pelo Facebook foi cancelado.';
}
