<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * O que o polling da pergunta ao BlueJus IA devolve: só o estado (nunca o texto da pergunta nem da resposta, que a tela
 * lê pelo histórico da conversa). `mensagem` é um texto FIXO para `falhou` e `indisponivel`; o motivo técnico do
 * provedor não sai daqui.
 */
final readonly class StatusDaPerguntaDaIaOutput
{
    public function __construct(
        public int $analiseId,
        public int $conversaId,
        public string $status,
        public bool $emAndamento,
        public bool $terminal,
        public ?string $mensagem,
    ) {
    }
}
