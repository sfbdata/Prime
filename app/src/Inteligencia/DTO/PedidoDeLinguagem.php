<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que vai para o provedor de linguagem — formato neutro de fornecedor. `sistema` é a instrução
 * do sistema; `mensagens` é a conversa (`papel` = 'usuario' | 'assistente'). `rotuloDeUso` serve
 * para o adaptador etiquetar a chamada (custo/log), nunca entra no prompt.
 *
 * Nunca é logado inteiro: carrega o texto das publicações (conteúdo processual).
 */
final readonly class PedidoDeLinguagem
{
    /**
     * @param list<array{papel: string, conteudo: string}> $mensagens
     */
    public function __construct(
        public string $sistema,
        public array $mensagens,
        public int $maxTokens,
        public float $temperatura,
        public bool $exigeJson,
        public string $rotuloDeUso,
    ) {
    }

    /** Conteúdo das mensagens do usuário, concatenado — o que o modelo lê além do sistema. */
    public function textoDoUsuario(): string
    {
        $partes = [];
        foreach ($this->mensagens as $mensagem) {
            if ($mensagem['papel'] === 'usuario') {
                $partes[] = $mensagem['conteudo'];
            }
        }

        return implode("\n\n", $partes);
    }
}
