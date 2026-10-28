<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

/**
 * Lê o SSE da API de Mensagens da Anthropic (`stream: true`) pedaço a pedaço. Sem rede: recebe o que o cliente HTTP
 * entregou, em qualquer fatiamento, e devolve os trechos de texto prontos.
 *
 * Eventos usados (documentação "Streaming Messages"):
 * - `message_start`: `message.model` e `message.usage.input_tokens`;
 * - `content_block_delta` com `delta.type = text_delta`: o texto (`thinking_delta` e `input_json_delta` são ignorados);
 * - `message_delta`: `delta.stop_reason` e `usage.output_tokens` (valor acumulado);
 * - `message_stop`: fim normal;
 * - `error`: `error.type` (`overloaded_error`, `api_error`, `rate_limit_error`...) no meio do fluxo;
 * - `ping` e os demais: ignorados.
 *
 * Nunca guarda nem expõe o corpo bruto: do `error` só o tipo (letras e `_`). JSON torto no `data:` é ignorado.
 */
final class LeitorDeFluxoDaAnthropic
{
    private string $pendente = '';
    private bool $terminou = false;
    private ?string $modelo = null;
    private ?int $tokensEntrada = null;
    private ?int $tokensSaida = null;
    private ?string $parada = null;
    private ?string $erro = null;

    /** @return list<string> os trechos de texto que este pedaço completou */
    public function alimentar(string $pedaco): array
    {
        $this->pendente = str_replace("\r\n", "\n", $this->pendente . $pedaco);
        $trechos = [];
        while (($fim = strpos($this->pendente, "\n\n")) !== false) {
            $bloco = substr($this->pendente, 0, $fim);
            $this->pendente = substr($this->pendente, $fim + 2);
            $texto = $this->processar($bloco);
            if ($texto !== '') {
                $trechos[] = $texto;
            }
        }

        return $trechos;
    }

    /** O fim normal (`message_stop`) chegou? */
    public function terminou(): bool
    {
        return $this->terminou;
    }

    /** O tipo do erro que veio dentro do fluxo (só letras e `_`), ou nulo. */
    public function erro(): ?string
    {
        return $this->erro;
    }

    public function modelo(): ?string
    {
        return $this->modelo;
    }

    public function tokensEntrada(): ?int
    {
        return $this->tokensEntrada;
    }

    public function tokensSaida(): ?int
    {
        return $this->tokensSaida;
    }

    public function cortadaNoLimite(): bool
    {
        return $this->parada === 'max_tokens';
    }

    public function recusou(): bool
    {
        return $this->parada === 'refusal';
    }

    /** Um bloco `event:`/`data:`; devolve o texto do `text_delta` ou ''. */
    private function processar(string $bloco): string
    {
        $dados = '';
        foreach (explode("\n", $bloco) as $linha) {
            if (str_starts_with($linha, 'data:')) {
                $dados .= ltrim(substr($linha, 5), ' ');
            }
        }
        $evento = $dados === '' ? null : json_decode($dados, true);
        if (!\is_array($evento) || !\is_string($evento['type'] ?? null)) {
            return '';
        }

        return match ($evento['type']) {
            'content_block_delta' => self::textoDoDelta($evento),
            'message_start' => $this->aoComecar($evento),
            'message_delta' => $this->aoAtualizar($evento),
            'message_stop' => $this->aoTerminar(),
            'error' => $this->aoFalhar($evento),
            default => '',
        };
    }

    /** @param array<string, mixed> $evento */
    private static function textoDoDelta(array $evento): string
    {
        $delta = $evento['delta'] ?? null;
        if (!\is_array($delta) || ($delta['type'] ?? null) !== 'text_delta' || !\is_string($delta['text'] ?? null)) {
            return '';
        }

        return $delta['text'];
    }

    /** @param array<string, mixed> $evento */
    private function aoComecar(array $evento): string
    {
        $mensagem = \is_array($evento['message'] ?? null) ? $evento['message'] : [];
        $this->modelo = \is_string($mensagem['model'] ?? null) ? $mensagem['model'] : null;
        $uso = \is_array($mensagem['usage'] ?? null) ? $mensagem['usage'] : [];
        $this->tokensEntrada = \is_int($uso['input_tokens'] ?? null) ? $uso['input_tokens'] : $this->tokensEntrada;

        return '';
    }

    /** @param array<string, mixed> $evento */
    private function aoAtualizar(array $evento): string
    {
        $delta = \is_array($evento['delta'] ?? null) ? $evento['delta'] : [];
        $this->parada = \is_string($delta['stop_reason'] ?? null) ? $delta['stop_reason'] : $this->parada;
        $uso = \is_array($evento['usage'] ?? null) ? $evento['usage'] : [];
        $this->tokensSaida = \is_int($uso['output_tokens'] ?? null) ? $uso['output_tokens'] : $this->tokensSaida;
        if (\is_int($uso['input_tokens'] ?? null)) {
            $this->tokensEntrada = $uso['input_tokens'];
        }

        return '';
    }

    private function aoTerminar(): string
    {
        $this->terminou = true;

        return '';
    }

    /** @param array<string, mixed> $evento */
    private function aoFalhar(array $evento): string
    {
        $erro = \is_array($evento['error'] ?? null) ? $evento['error'] : [];
        $tipo = \is_string($erro['type'] ?? null) ? preg_replace('/[^a-z_]/', '', $erro['type']) : '';
        $this->erro = $tipo !== '' && $tipo !== null ? $tipo : 'desconhecido';

        return '';
    }
}
