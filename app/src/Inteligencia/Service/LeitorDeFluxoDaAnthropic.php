<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\ChamadaDeFerramenta;

/**
 * Lê o SSE da API de Mensagens da Anthropic (`stream: true`) pedaço a pedaço. Sem rede: recebe o que o cliente HTTP
 * entregou, em qualquer fatiamento, e devolve os trechos de texto prontos.
 *
 * Eventos usados (documentação "Streaming Messages"):
 * - `message_start`: `message.model` e `message.usage.input_tokens`;
 * - `content_block_delta` com `delta.type = text_delta`: o texto (`thinking_delta` é ignorado);
 * - tool use: `content_block_start` com `content_block.type = tool_use` abre a chamada (nome), os `input_json_delta`
 *   (`partial_json`) dela são juntados e o `content_block_stop` a fecha decodificando o JSON. JSON torto, que não é
 *   objeto, grande demais ou de bloco que não chegou ao fim = chamada descartada;
 * - `message_delta`: `delta.stop_reason` e `usage.output_tokens` (valor acumulado);
 * - `message_stop`: fim normal;
 * - `error`: `error.type` (`overloaded_error`, `api_error`, `rate_limit_error`...) no meio do fluxo;
 * - `ping` e os demais: ignorados.
 *
 * Nunca guarda nem expõe o corpo bruto: do `error` só o tipo (letras e `_`). JSON torto no `data:` é ignorado.
 */
final class LeitorDeFluxoDaAnthropic
{
    /** Teto do JSON de uma chamada de ferramenta: um cadastro cabe folgado, e o resto é lixo. */
    private const TETO_DO_JSON_DA_CHAMADA = 65_536;

    private string $pendente = '';
    private bool $terminou = false;
    private ?string $modelo = null;
    private ?int $tokensEntrada = null;
    private ?int $tokensSaida = null;
    private ?string $parada = null;
    private ?string $erro = null;
    /** @var array<int, array{nome: string, json: ?string}> chamadas abertas por índice do bloco: nome e JSON parcial (nulo = estourou o teto) */
    private array $abertas = [];
    /** @var list<ChamadaDeFerramenta> */
    private array $chamadas = [];

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

    /** @return list<ChamadaDeFerramenta> as chamadas de ferramenta completas, na ordem em que o modelo as pediu */
    public function chamadasDeFerramenta(): array
    {
        return $this->chamadas;
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
            'content_block_delta' => $this->aoReceberDelta($evento),
            'content_block_start' => $this->aoAbrirBloco($evento),
            'content_block_stop' => $this->aoFecharBloco($evento),
            'message_start' => $this->aoComecar($evento),
            'message_delta' => $this->aoAtualizar($evento),
            'message_stop' => $this->aoTerminar(),
            'error' => $this->aoFalhar($evento),
            default => '',
        };
    }

    /** @param array<string, mixed> $evento */
    private function aoReceberDelta(array $evento): string
    {
        $delta = $evento['delta'] ?? null;
        if (!\is_array($delta)) {
            return '';
        }
        if (($delta['type'] ?? null) === 'input_json_delta') {
            $this->juntarOJson($evento['index'] ?? null, $delta['partial_json'] ?? null);

            return '';
        }
        if (($delta['type'] ?? null) !== 'text_delta' || !\is_string($delta['text'] ?? null)) {
            return '';
        }

        return $delta['text'];
    }

    /** @param array<string, mixed> $evento */
    private function aoAbrirBloco(array $evento): string
    {
        $bloco = $evento['content_block'] ?? null;
        if (\is_int($evento['index'] ?? null) && \is_array($bloco) && ($bloco['type'] ?? null) === 'tool_use' && \is_string($bloco['name'] ?? null)) {
            $this->abertas[$evento['index']] = ['nome' => $bloco['name'], 'json' => ''];
        }

        return '';
    }

    private function juntarOJson(mixed $indice, mixed $parcial): void
    {
        if (!\is_int($indice) || !isset($this->abertas[$indice]) || !\is_string($parcial)) {
            return;
        }
        $json = $this->abertas[$indice]['json'];
        if ($json === null || \strlen($json) + \strlen($parcial) > self::TETO_DO_JSON_DA_CHAMADA) {
            $this->abertas[$indice]['json'] = null;

            return;
        }
        $this->abertas[$indice]['json'] = $json . $parcial;
    }

    /** @param array<string, mixed> $evento */
    private function aoFecharBloco(array $evento): string
    {
        $indice = $evento['index'] ?? null;
        if (!\is_int($indice) || !isset($this->abertas[$indice])) {
            return '';
        }
        $aberta = $this->abertas[$indice];
        unset($this->abertas[$indice]);
        // Sem entrada nenhuma a API manda `input: {}` no start e nenhum delta: objeto vazio.
        $entrada = $aberta['json'] === null ? null : json_decode($aberta['json'] === '' ? '{}' : $aberta['json'], true);
        if (\is_array($entrada) && !array_is_list($entrada)) {
            $this->chamadas[] = new ChamadaDeFerramenta($aberta['nome'], $entrada);
        } elseif ($entrada === []) {
            $this->chamadas[] = new ChamadaDeFerramenta($aberta['nome'], []);
        }

        return '';
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
