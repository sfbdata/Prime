<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * Mensagem comum numa conversa com o BlueJus IA. Nela só se pergunta (`PerguntarABlueJusIaUseCase`): a tela responde
 * 403 com `codigo: conversa_ia`, que não se confunde com sessão encerrada.
 */
final class EnvioNaConversaComAIaException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Na conversa com o BlueJus IA só se faz perguntas.');
    }
}
