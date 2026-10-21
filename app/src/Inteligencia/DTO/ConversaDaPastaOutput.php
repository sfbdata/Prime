<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * A conversa da pessoa logada na pasta (aba "Perguntar"): as últimas perguntas em ordem
 * cronológica, cada uma com a resposta ou o estado dela. Só da pessoa: ninguém vê a de outra.
 */
final readonly class ConversaDaPastaOutput
{
    /** @param list<MensagemDaConversaOutput> $mensagens */
    public function __construct(
        public array $mensagens,
    ) {
    }

    public function vazia(): bool
    {
        return $this->mensagens === [];
    }

    /** Há pergunta esperando resposta: a tela mostra o cartão de carregando e faz o polling. */
    public function temEmAndamento(): bool
    {
        foreach ($this->mensagens as $mensagem) {
            if ($mensagem->emAndamento) {
                return true;
            }
        }

        return false;
    }
}
