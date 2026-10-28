<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

/**
 * Escreve um evento SSE (`event:` + `data:` em JSON de uma linha) e empurra para o cliente na hora. Em produção esvazia
 * os buffers de saída do PHP antes de começar (`chat_ia_sse_esvaziar_buffers`), senão o trecho só chega quando o buffer
 * enche; no teste o parâmetro é falso para a saída continuar capturável.
 */
final class EscritorDeSse
{
    private bool $iniciado = false;

    public function __construct(private readonly bool $esvaziarBuffers)
    {
    }

    /** @param array<string, mixed> $dados */
    public function escrever(string $evento, array $dados): void
    {
        $this->iniciar();
        echo 'event: ' . $evento . "\n" . 'data: ' . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\n";
        if ($this->esvaziarBuffers) {
            flush();
        }
    }

    private function iniciar(): void
    {
        if ($this->iniciado) {
            return;
        }
        $this->iniciado = true;
        if ($this->esvaziarBuffers) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
    }
}
