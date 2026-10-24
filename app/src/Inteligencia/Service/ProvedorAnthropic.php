<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcecaoDeHttp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptador da API de Mensagens da Anthropic (Claude) para a porta {@see ProvedorDeLinguagem} — decisão D1 do
 * dono (14/10/2026): o provedor é exclusivamente a Anthropic. Contrato: `docs/specs/inteligencia-provedor-anthropic.md`.
 *
 * **Dormente sem configuração.** Sem `ANTHROPIC_API_KEY` e `IA_MODELO` no ambiente, `estaConfigurado()` é falso e
 * `completar()` lança {@see ProvedorIndisponivelException} — o mesmo comportamento do `ProvedorNaoConfigurado`. Ligar
 * a IA continua exigindo, além disso, `IA_HABILITADA=1`, o escritório habilitado e o consentimento dele
 * (`DisponibilidadeDeInteligencia`). A chave nunca está no código.
 *
 * O que vai e o que não vai na requisição:
 * - vai `model`, `max_tokens`, `system` e `messages` (papel `usuario` → `user`, `assistente` → `assistant`);
 * - **não vai `temperature`**: os modelos atuais (Opus 4.7 em diante) recusam com 400 os parâmetros de amostragem.
 *   A `temperatura` do pedido fica como intenção do prompt, não como parâmetro de rede;
 * - **não vai formato estruturado**: `exigeJson` é cumprido pela instrução do prompt e verificado pelos
 *   interpretadores, que já recusam resposta fora do formato;
 * - nenhum identificador de escritório, usuário ou pasta vai em metadado;
 * - `output_config.effort` só vai quando `IA_ESFORCO` está definido (o padrão muda por modelo — é a alavanca de custo);
 * - `inference_geo` vai quando `IA_INFERENCE_GEO` está definido (`us` por padrão, decisão de 24/10/2026: o
 *   processamento fica nos EUA, que é o país que a Política de Privacidade declara);
 * - pedido cujo último turno é do assistente (prefill) é recusado aqui: os modelos atuais respondem 400;
 * - `max_tokens` = o teto do prompt + a margem de raciocínio (`IA_MARGEM_RACIOCINIO`): nos modelos atuais o raciocínio
 *   adaptativo conta no `max_tokens`, e sem margem um resumo de 1.200 tokens seria cortado — falha com custo pago.
 *
 * **Segredo.** A chave entra aparada; com caractere de controle ou espaço no meio, o provedor fica "não configurado"
 * (nunca vai para um cabeçalho que o cliente HTTP recusaria citando o valor). A exceção de transporte **não é
 * encadeada**: a mensagem e os argumentos dela carregam o cabeçalho, e o Messenger serializa o `previous` na fila. Sem
 * redirecionamento: o `x-api-key` não segue para outro host.
 *
 * Erros: rede, timeout, 429, 5xx e 529 são **transitórios** (o worker tenta de novo); 4xx são definitivos — e o 429
 * do teto mensal de gasto (`enforced_spend_limit_reached`) também, porque repetir não adianta até o mês virar. A mensagem
 * da exceção leva só status, tipo do erro e id da requisição — ela é gravada como motivo da análise, e o corpo da
 * resposta de erro nunca é copiado para ela. O texto do pedido nunca é logado nem posto em exceção.
 */
final class ProvedorAnthropic implements ProvedorDeLinguagem
{
    public const NOME = 'anthropic';
    public const VERSAO_DA_API = '2023-06-01';

    private const STATUS_TRANSITORIOS = [408, 429, 500, 502, 503, 504, 529];
    private const ESFORCOS = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const MARGEM_DE_RACIOCINIO_PADRAO = 8000;
    /** Vazio = não envia (vale o padrão do workspace na Console). */
    private const REGIOES_DE_INFERENCIA = ['', 'us', 'global'];
    /** Teto da espera pedida pelo `retry-after`: cinco minutos. */
    public const ESPERA_MAXIMA_EM_SEGUNDOS = 300;

    private readonly string $chave;
    private readonly string $modelo;
    private readonly string $esforco;
    private readonly bool $configuracaoValida;
    private readonly string $regiaoDeInferencia;

    /**
     * Nunca lança: configuração torta (esforço desconhecido, chave com controle) deixa o provedor "não configurado".
     * Uma exceção aqui derrubaria toda página que monta a extensão Twig da IA.
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $chave,
        string $modelo,
        private readonly string $urlBase = 'https://api.anthropic.com',
        private readonly float $timeoutEmSegundos = 120.0,
        string $esforco = '',
        private readonly int $margemDeRaciocinio = self::MARGEM_DE_RACIOCINIO_PADRAO,
        string $regiaoDeInferencia = '',
    ) {
        $this->regiaoDeInferencia = strtolower(trim($regiaoDeInferencia));
        $this->chave = trim($chave);
        $this->modelo = trim($modelo);
        $this->esforco = strtolower(trim($esforco));
        $this->configuracaoValida = $this->chave !== ''
            && $this->modelo !== ''
            && preg_match('/^[\x21-\x7E]+$/', $this->chave) === 1
            && preg_match('/^[a-z0-9.\-]+$/', $this->modelo) === 1
            && ($this->esforco === '' || in_array($this->esforco, self::ESFORCOS, true))
            && $this->margemDeRaciocinio >= 0
            && in_array($this->regiaoDeInferencia, self::REGIOES_DE_INFERENCIA, true);
    }

    /** `nao_configurado` enquanto dormente: a coluna `provedor` da análise não pode dizer que algo saiu para a Anthropic. */
    public function nome(): string
    {
        return $this->configuracaoValida ? self::NOME : ProvedorNaoConfigurado::NOME;
    }

    public function estaConfigurado(): bool
    {
        return $this->configuracaoValida;
    }

    public function completar(PedidoDeLinguagem $pedido): RespostaDeLinguagem
    {
        if (!$this->estaConfigurado()) {
            throw new ProvedorIndisponivelException('Provedor de IA (Anthropic) sem chave ou modelo configurado nesta instalação.');
        }

        $corpo = $this->corpo($pedido);
        $inicio = hrtime(true);

        try {
            $resposta = $this->httpClient->request('POST', rtrim($this->urlBase, '/') . '/v1/messages', [
                'headers' => [
                    'x-api-key'         => $this->chave,
                    'anthropic-version' => self::VERSAO_DA_API,
                    'content-type'      => 'application/json',
                ],
                'json'          => $corpo,
                'timeout'       => $this->timeoutEmSegundos,
                'max_redirects' => 0,
            ]);
            $status = $resposta->getStatusCode();
            $cabecalhos = $resposta->getHeaders(false);
        } catch (ExcecaoDeHttp $e) {
            // Rede, DNS, TLS ou timeout: nada disso depende do pedido — tenta de novo. Sem encadear `$e` (ver docblock).
            throw new FalhaDoProvedorException(sprintf('Anthropic: falha de comunicação (%s).', (new \ReflectionClass($e))->getShortName()), true);
        }

        $requisicao = $cabecalhos['request-id'][0] ?? null;
        try {
            $dados = $resposta->toArray(false);
        } catch (ExcecaoDeHttp) {
            // Corpo que não é JSON (página de proxy, corpo vazio): o STATUS decide — um 401/413 de proxy não melhora
            // repetindo, um 502 sim.
            $dados = [];
            if ($status === 200) {
                throw new FalhaDoProvedorException($this->comRequisicao('Anthropic: resposta que não é JSON.', $requisicao), true);
            }
        }
        if ($status !== 200) {
            throw $this->falhaPorStatus($status, $dados, $requisicao, self::segundosDeEspera($cabecalhos['retry-after'][0] ?? null));
        }

        return $this->resposta($dados, $requisicao, (int) ((hrtime(true) - $inicio) / 1_000_000));
    }

    /** @return array<string, mixed> */
    private function corpo(PedidoDeLinguagem $pedido): array
    {
        $mensagens = [];
        foreach ($pedido->mensagens as $mensagem) {
            $mensagens[] = [
                'role'    => match ($mensagem['papel']) {
                    'usuario'    => 'user',
                    'assistente' => 'assistant',
                    // Falha definitiva, e não LogicException: o worker só trata as falhas do provedor, e a análise
                    // ficaria presa em "processando".
                    default      => throw new FalhaDoProvedorException('Anthropic: papel de mensagem desconhecido no pedido.', false),
                },
                'content' => $mensagem['conteudo'],
            ];
        }

        if ($mensagens === [] || $mensagens[array_key_last($mensagens)]['role'] !== 'user') {
            throw new FalhaDoProvedorException('Anthropic: o pedido precisa terminar com uma mensagem do usuário.', false);
        }

        $corpo = [
            'model'      => $this->modelo,
            'max_tokens' => $pedido->maxTokens + $this->margemDeRaciocinio,
            'messages'   => $mensagens,
        ];
        if ($this->esforco !== '') {
            $corpo['output_config'] = ['effort' => $this->esforco];
        }
        if ($this->regiaoDeInferencia !== '') {
            $corpo['inference_geo'] = $this->regiaoDeInferencia;
        }
        if (trim($pedido->sistema) !== '') {
            $corpo['system'] = $pedido->sistema;
        }

        return $corpo;
    }

    /** @param array<string, mixed> $dados */
    private function resposta(array $dados, ?string $requisicao, int $duracaoMs): RespostaDeLinguagem
    {
        $parada = is_string($dados['stop_reason'] ?? null) ? $dados['stop_reason'] : '';
        if ($parada === 'refusal') {
            throw new FalhaDoProvedorException($this->comRequisicao('Anthropic: o modelo recusou responder a este pedido.', $requisicao), false);
        }
        if ($parada === 'max_tokens') {
            // Repetir o mesmo pedido corta no mesmo lugar: não é transitório. O conserto é o limite do prompt.
            throw new FalhaDoProvedorException($this->comRequisicao('Anthropic: a resposta foi cortada no limite de tokens.', $requisicao), false);
        }

        $texto = '';
        foreach (is_array($dados['content'] ?? null) ? $dados['content'] : [] as $bloco) {
            if (is_array($bloco) && ($bloco['type'] ?? null) === 'text' && is_string($bloco['text'] ?? null)) {
                $texto .= $bloco['text'];
            }
        }
        if (trim($texto) === '') {
            throw new FalhaDoProvedorException($this->comRequisicao('Anthropic: resposta sem texto.', $requisicao), false);
        }

        $uso = is_array($dados['usage'] ?? null) ? $dados['usage'] : [];

        return new RespostaDeLinguagem(
            texto: $texto,
            modelo: is_string($dados['model'] ?? null) ? $dados['model'] : $this->modelo,
            tokensEntrada: self::inteiroOuNulo($uso['input_tokens'] ?? null),
            tokensSaida: self::inteiroOuNulo($uso['output_tokens'] ?? null),
            duracaoMs: $duracaoMs,
        );
    }

    /** @param array<string, mixed> $dados */
    private function falhaPorStatus(int $status, array $dados, ?string $requisicao, ?int $esperarSegundos): FalhaDoProvedorException
    {
        $tipo = is_array($dados['error'] ?? null) && is_string($dados['error']['type'] ?? null)
            ? preg_replace('/[^a-z_]/', '', $dados['error']['type'])
            : 'desconhecido';

        $codigo = is_array($dados['error'] ?? null) && is_array($dados['error']['details'] ?? null)
            ? ($dados['error']['details']['error_code'] ?? null)
            : null;
        if ($codigo === 'enforced_spend_limit_reached') {
            return new FalhaDoProvedorException(
                $this->comRequisicao(sprintf('Anthropic: HTTP %d (teto mensal de gasto da conta atingido).', $status), $requisicao),
                false,
            );
        }

        $transitoria = in_array($status, self::STATUS_TRANSITORIOS, true);

        return new FalhaDoProvedorException(
            $this->comRequisicao(sprintf('Anthropic: HTTP %d (%s).', $status, $tipo), $requisicao),
            $transitoria,
            esperarSegundos: $transitoria ? $esperarSegundos : null,
        );
    }

    /**
     * `retry-after` em segundos inteiros (a Anthropic não usa a forma de data). Valor torto ou ausente = sem prazo
     * (vale o atraso do transport); acima do teto, o teto: uma linha não fica horas "em andamento".
     */
    private static function segundosDeEspera(?string $valor): ?int
    {
        if ($valor === null || preg_match('/^\d{1,6}$/', trim($valor)) !== 1) {
            return null;
        }

        return min((int) trim($valor), self::ESPERA_MAXIMA_EM_SEGUNDOS);
    }

    private function comRequisicao(string $mensagem, ?string $requisicao): string
    {
        if ($requisicao === null || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $requisicao) !== 1) {
            return $mensagem;
        }

        return $mensagem . ' Requisição ' . $requisicao . '.';
    }

    private static function inteiroOuNulo(mixed $valor): ?int
    {
        return is_int($valor) ? $valor : null;
    }
}
