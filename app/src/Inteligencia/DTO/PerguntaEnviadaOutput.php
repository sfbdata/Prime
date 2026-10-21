<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Resposta do `POST /pasta/{id}/ia/conversa/perguntas`: a pergunta (nova ou a que já estava em
 * andamento) e a análise dela, no mesmo JSON do pedido de comando, com o id da pergunta.
 */
final readonly class PerguntaEnviadaOutput
{
    public function __construct(
        public MensagemDaConversaOutput $pergunta,
        public AnaliseOutput $analise,
    ) {
    }

    public function emAndamento(): bool
    {
        return $this->analise->emAndamento;
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return $this->analise->paraJson() + ['pergunta' => $this->pergunta->perguntaId];
    }
}
