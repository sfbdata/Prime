<?php

declare(strict_types=1);

namespace App\Auth\Exception;

/** O "Entrar com o Google" não deu: a mensagem é a que a tela de entrada mostra à pessoa. */
final class EntradaPeloGoogleRecusadaException extends \RuntimeException
{
    public const SEM_CONTA = 'Não encontramos conta com este e-mail. Crie sua conta e escritório.';
    public const OUTRA_CONTA_GOOGLE = 'Esta conta do BlueJus já está ligada a outra conta Google. Entre com e-mail e senha.';
    public const FALHOU = 'Não foi possível entrar com o Google. Tente de novo.';
    public const CANCELADA = 'A entrada com o Google foi cancelada.';
}
