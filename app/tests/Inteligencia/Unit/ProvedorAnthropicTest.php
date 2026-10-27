<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\AnexoDeDocumento;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\FonteDoModeloDeIa;
use App\Inteligencia\Service\ProvedorAnthropic;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O adaptador da Anthropic sem tocar a rede: `MockHttpClient` responde no lugar da API. Prova o formato da
 * requisição, a leitura da resposta, a classificação dos erros (transitório × definitivo) e que nenhum conteúdo do
 * pedido vaza para a mensagem de erro — que é gravada como motivo da análise.
 */
#[CoversClass(ProvedorAnthropic::class)]
final class ProvedorAnthropicTest extends TestCase
{
    private const SEGREDO_DO_PROCESSO = 'CONTEUDO-PROCESSUAL-SIGILOSO';

    private function pedido(): PedidoDeLinguagem
    {
        return new PedidoDeLinguagem(
            sistema: 'Você resume publicações.',
            mensagens: [
                ['papel' => 'usuario', 'conteudo' => 'Publicação: ' . self::SEGREDO_DO_PROCESSO],
                ['papel' => 'assistente', 'conteudo' => 'Entendido.'],
                ['papel' => 'usuario', 'conteudo' => 'Resuma.'],
            ],
            maxTokens: 1200,
            temperatura: 0.2,
            exigeJson: true,
            rotuloDeUso: 'push/v1',
        );
    }

    /** @param array<string, mixed> $corpo */
    private static function ok(array $corpo = [], array $cabecalhos = []): MockResponse
    {
        return new MockResponse(json_encode($corpo + [
            'id'          => 'msg_1',
            'type'        => 'message',
            'role'        => 'assistant',
            'model'       => 'modelo-da-resposta',
            'content'     => [['type' => 'text', 'text' => '{"resumo":"ok"}']],
            'stop_reason' => 'end_turn',
            'usage'       => ['input_tokens' => 321, 'output_tokens' => 45],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => $cabecalhos + ['content-type' => 'application/json']]);
    }

    private static function provedor(MockHttpClient $http, string $chave = 'sk-teste', string $modelo = 'modelo-configurado'): ProvedorAnthropic
    {
        return new ProvedorAnthropic($http, $chave, $modelo, 'https://api.exemplo.test');
    }

    #[TestDox('Sem chave, sem modelo ou com chave/modelo tortos: não configurado, nome "nao_configurado", sem rede')]
    public function testSemConfiguracao(): void
    {
        foreach ([['', 'modelo'], ['sk', ''], ['  ', '  '], ["sk-ant-1\r\nX-Injetado: 1", 'modelo'], ['sk ant', 'modelo'], ['sk', 'modelo; DROP']] as [$chave, $modelo]) {
            $http = new MockHttpClient(static fn () => throw new \LogicException('não deveria chamar a rede'));
            $provedor = self::provedor($http, $chave, $modelo);
            self::assertFalse($provedor->estaConfigurado());
            self::assertSame(ProvedorNaoConfigurado::NOME, $provedor->nome(), 'a coluna provedor não pode dizer que algo saiu');

            try {
                $provedor->completar($this->pedido());
                self::fail('Deveria ser indisponível.');
            } catch (ProvedorIndisponivelException) {
                self::assertSame(0, $http->getRequestsCount());
            }
        }
    }

    private static function fonte(\Closure $modelo): FonteDoModeloDeIa
    {
        return new class ($modelo) implements FonteDoModeloDeIa {
            public int $chamadas = 0;

            public function __construct(private readonly \Closure $modelo)
            {
            }

            public function modelo(): string
            {
                ++$this->chamadas;

                return ($this->modelo)();
            }
        };
    }

    private static function comFonte(MockHttpClient $http, FonteDoModeloDeIa $fonte, string $chave = 'sk-teste', string $modelo = 'modelo-do-ambiente'): ProvedorAnthropic
    {
        return new ProvedorAnthropic($http, $chave, $modelo, 'https://api.exemplo.test', 120.0, '', ProvedorAnthropic::MARGEM_DE_RACIOCINIO_PADRAO, fonteDoModelo: $fonte);
    }

    #[TestDox('Com a fonte do modelo (o banco), o modelo dela manda em cada chamada, não o do ambiente')]
    public function testModeloDoBancoManda(): void
    {
        $modelos = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$modelos): MockResponse {
            $modelos[] = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR)['model'];

            return self::ok();
        });
        $atual = 'claude-haiku-5-5';
        $provedor = self::comFonte($http, self::fonte(static function () use (&$atual): string {
            return $atual;
        }));

        self::assertTrue($provedor->estaConfigurado());
        self::assertSame('claude-haiku-5-5', $provedor->modeloEfetivo());
        $provedor->completar($this->pedido());
        $atual = 'claude-opus-5-5';
        $provedor->completar($this->pedido());

        self::assertSame(['claude-haiku-5-5', 'claude-opus-5-5'], $modelos, 'o Master troca o modelo sem reiniciar o worker');
    }

    #[TestDox('Fonte do modelo que lança (banco fora, tabela ausente): cai no modelo do ambiente, sem derrubar quem pergunta')]
    public function testFonteQueLancaCaiNoAmbiente(): void
    {
        $capturado = null;
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$capturado): MockResponse {
            $capturado = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR)['model'];

            return self::ok();
        });
        $provedor = self::comFonte($http, self::fonte(static fn (): string => throw new \RuntimeException('banco fora')));

        self::assertTrue($provedor->estaConfigurado());
        self::assertSame('modelo-do-ambiente', $provedor->modeloEfetivo());
        $provedor->completar($this->pedido());
        self::assertSame('modelo-do-ambiente', $capturado);
    }

    #[TestDox('Modelo da fonte vazio ou fora do formato: dormente, nome "nao_configurado", nenhuma chamada sai')]
    public function testModeloInvalidoDaFonteEhDormente(): void
    {
        foreach (['', '  ', 'claude; DROP', 'Claude-Haiku'] as $invalido) {
            $http = new MockHttpClient(static fn () => throw new \LogicException('não deveria chamar a rede'));
            $provedor = self::comFonte($http, self::fonte(static fn (): string => $invalido));

            self::assertFalse($provedor->estaConfigurado(), $invalido);
            self::assertSame(ProvedorNaoConfigurado::NOME, $provedor->nome());
            try {
                $provedor->completar($this->pedido());
                self::fail('Deveria ser indisponível.');
            } catch (ProvedorIndisponivelException) {
                self::assertSame(0, $http->getRequestsCount());
            }
        }
    }

    #[TestDox('Sem chave, a fonte do modelo nem é consultada (instalação dormente não vai ao banco)')]
    public function testSemChaveNaoConsultaAFonte(): void
    {
        $fonte = self::fonte(static fn (): string => 'claude-haiku-5-5');
        $provedor = self::comFonte(new MockHttpClient(), $fonte, chave: '');

        self::assertFalse($provedor->estaConfigurado());
        self::assertSame(0, $fonte->chamadas);
    }

    #[TestDox('A requisição: endpoint, cabeçalhos, modelo, sistema, papéis traduzidos — e sem temperature')]
    public function testFormatoDaRequisicao(): void
    {
        $capturado = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$capturado): MockResponse {
            $capturado = ['metodo' => $metodo, 'url' => $url, 'opcoes' => $opcoes];

            return self::ok();
        });

        self::provedor($http)->completar($this->pedido());

        self::assertSame('anthropic', self::provedor($http)->nome());
        self::assertSame('POST', $capturado['metodo']);
        self::assertSame(0, $capturado['opcoes']['max_redirects'], 'o x-api-key não segue redirecionamento');
        self::assertSame('https://api.exemplo.test/v1/messages', $capturado['url']);
        $cabecalhos = implode("\n", $capturado['opcoes']['headers']);
        self::assertStringContainsString('x-api-key: sk-teste', $cabecalhos);
        self::assertStringContainsString('anthropic-version: 2023-06-01', $cabecalhos);

        $corpo = json_decode($capturado['opcoes']['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('modelo-configurado', $corpo['model']);
        self::assertSame(['model', 'max_tokens', 'messages', 'system'], array_keys($corpo), 'nada além disso sai');
        self::assertSame(1200 + ProvedorAnthropic::MARGEM_DE_RACIOCINIO_PADRAO, $corpo['max_tokens'], 'teto do prompt + margem de raciocínio');
        self::assertSame('Você resume publicações.', $corpo['system']);
        self::assertSame(['user', 'assistant', 'user'], array_column($corpo['messages'], 'role'));
        self::assertArrayNotHasKey('temperature', $corpo, 'os modelos atuais recusam parâmetros de amostragem');
        self::assertArrayNotHasKey('metadata', $corpo, 'nenhum identificador de escritório/usuário sai');
    }

    #[TestDox('Com anexos: PDF e imagem vão como blocos base64 só na última mensagem do usuário, com timeout de 300 s; o resto continua string')]
    public function testAnexosVaoComoBlocosNaUltimaMensagem(): void
    {
        $capturado = null;
        $timeout = null;
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$capturado, &$timeout): MockResponse {
            $capturado = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR);
            $timeout = $opcoes['timeout'];

            return self::ok();
        });
        $base = $this->pedido();
        $pedido = new PedidoDeLinguagem($base->sistema, $base->mensagens, $base->maxTokens, $base->temperatura, $base->exigeJson, $base->rotuloDeUso, [
            new AnexoDeDocumento(3, 'inicial.pdf', AnexoDeDocumento::PDF, '%PDF-1.7 conteudo'),
            new AnexoDeDocumento(4, 'print.jpg', 'image/jpeg', "\xFF\xD8\xFFimagem"),
        ]);

        self::provedor($http)->completar($pedido);

        self::assertIsString($capturado['messages'][0]['content'], 'só a última mensagem leva os arquivos');
        self::assertIsString($capturado['messages'][1]['content']);
        $ultima = $capturado['messages'][2];
        self::assertSame('user', $ultima['role']);
        self::assertSame(['text', 'document', 'text', 'image', 'text'], array_column($ultima['content'], 'type'));
        self::assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.7 conteudo')], $ultima['content'][1]['source']);
        self::assertSame('image/jpeg', $ultima['content'][3]['source']['media_type']);
        self::assertSame('Resuma.', $ultima['content'][4]['text']);
        self::assertSame(['model', 'max_tokens', 'messages', 'system'], array_keys($capturado), 'nada além disso sai (sem Files API, sem metadado)');
        self::assertSame(ProvedorAnthropic::TIMEOUT_DA_LEITURA_EM_SEGUNDOS, $timeout, 'a leitura espera até 300 s');
        self::assertSame(300.0, ProvedorAnthropic::TIMEOUT_DA_LEITURA_EM_SEGUNDOS);
    }

    #[TestDox('Sem anexos: a última mensagem continua string e o timeout é o configurado')]
    public function testSemAnexosContinuaString(): void
    {
        $capturado = null;
        $timeout = null;
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$capturado, &$timeout): MockResponse {
            $capturado = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR);
            $timeout = $opcoes['timeout'];

            return self::ok();
        });

        self::provedor($http)->completar($this->pedido());

        self::assertSame('Resuma.', $capturado['messages'][2]['content']);
        self::assertSame(120.0, $timeout, 'sem anexos, o timeout configurado');
    }

    #[TestDox('A resposta: texto dos blocos de texto, modelo e tokens')]
    public function testLeituraDaResposta(): void
    {
        $http = new MockHttpClient(self::ok([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'raciocínio interno'],
                ['type' => 'text', 'text' => '{"resumo":'],
                ['type' => 'text', 'text' => '"ok"}'],
            ],
        ]));

        $resposta = self::provedor($http)->completar($this->pedido());

        self::assertSame('{"resumo":"ok"}', $resposta->texto);
        self::assertSame('modelo-da-resposta', $resposta->modelo);
        self::assertSame(321, $resposta->tokensEntrada);
        self::assertSame(45, $resposta->tokensSaida);
        self::assertNotNull($resposta->duracaoMs);
    }

    /** @return iterable<string, array{int, string, bool}> */
    public static function erros(): iterable
    {
        yield '400 pedido inválido'  => [400, 'invalid_request_error', false];
        yield '401 chave inválida'   => [401, 'authentication_error', false];
        yield '403 sem permissão'    => [403, 'permission_error', false];
        yield '404 modelo errado'    => [404, 'not_found_error', false];
        yield '413 grande demais'    => [413, 'request_too_large', false];
        yield '429 limite de taxa'   => [429, 'rate_limit_error', true];
        yield '408 timeout do pedido' => [408, 'timeout_error', true];
        yield '500 erro da API'      => [500, 'api_error', true];
        yield '502 gateway'          => [502, 'api_error', true];
        yield '503 indisponível'     => [503, 'api_error', true];
        yield '504 timeout'          => [504, 'timeout_error', true];
        yield '529 sobrecarregada'   => [529, 'overloaded_error', true];
    }

    #[DataProvider('erros')]
    #[TestDox('HTTP $status: transitório = $transitoria, com status, tipo e id da requisição — sem conteúdo')]
    public function testClassificacaoDosErros(int $status, string $tipo, bool $transitoria): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'type'  => 'error',
            'error' => ['type' => $tipo, 'message' => 'eco do pedido: ' . self::SEGREDO_DO_PROCESSO],
        ], JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', 'request-id' => 'req_abc123']]));

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertSame($transitoria, $e->transitoria);
            self::assertStringContainsString((string) $status, $e->getMessage());
            self::assertStringContainsString($tipo, $e->getMessage());
            self::assertStringContainsString('req_abc123', $e->getMessage());
            self::assertStringNotContainsString(self::SEGREDO_DO_PROCESSO, $e->getMessage());
        }
    }

    #[TestDox('429 do teto mensal de gasto é definitivo: repetir não adianta até o mês virar')]
    public function testTetoDeGasto(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'type'  => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'limite', 'details' => ['error_code' => 'enforced_spend_limit_reached']],
        ], JSON_THROW_ON_ERROR), ['http_code' => 429, 'response_headers' => ['content-type' => 'application/json']]));

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertFalse($e->transitoria);
            self::assertStringContainsString('teto mensal', $e->getMessage());
        }
    }

    #[TestDox('inference_geo vai quando configurado; valor desconhecido deixa o provedor dormente')]
    public function testRegiaoDeInferencia(): void
    {
        $corpo = null;
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$corpo): MockResponse {
            $corpo = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR);

            return self::ok();
        });

        (new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, '', 0, ' US '))->completar($this->pedido());

        self::assertSame('us', $corpo['inference_geo'] ?? null);
        self::assertTrue((new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, '', 0, 'global'))->estaConfigurado());
        self::assertFalse((new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, '', 0, 'br'))->estaConfigurado());
    }

    /** @return iterable<string, array{int, ?string, ?int}> */
    public static function esperas(): iterable
    {
        yield '429 com 30 s'         => [429, '30', 30];
        yield '529 acima do teto'    => [529, '86400', ProvedorAnthropic::ESPERA_MAXIMA_EM_SEGUNDOS];
        yield '429 sem cabeçalho'    => [429, null, null];
        yield '429 com data'         => [429, 'Wed, 21 Oct 2026 07:28:00 GMT', null];
        yield '400 não espera'       => [400, '30', null];
    }

    #[DataProvider('esperas')]
    #[TestDox('HTTP $status com retry-after $cabecalho → espera $esperado')]
    public function testRetryAfter(int $status, ?string $cabecalho, ?int $esperado): void
    {
        $cabecalhos = ['content-type' => 'application/json'];
        if ($cabecalho !== null) {
            $cabecalhos['retry-after'] = $cabecalho;
        }
        $http = new MockHttpClient(new MockResponse(json_encode([
            'type'  => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'devagar'],
        ], JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => $cabecalhos]));

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertSame($esperado, $e->esperarSegundos);
        }
    }

    #[TestDox('Pedido que termina com o assistente (prefill) é recusado antes da rede')]
    public function testPrefill(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('não deveria chamar a rede'));
        $pedido = new PedidoDeLinguagem('s', [['papel' => 'usuario', 'conteudo' => 'a'], ['papel' => 'assistente', 'conteudo' => '{']], 100, 0.0, true, 'x');

        try {
            self::provedor($http)->completar($pedido);
            self::fail('Deveria recusar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertFalse($e->transitoria);
            self::assertSame(0, $http->getRequestsCount());
        }
    }

    #[TestDox('O esforço só vai quando configurado; valor desconhecido deixa o provedor dormente, sem exceção')]
    public function testEsforco(): void
    {
        $corpos = [];
        $http = new MockHttpClient(function (string $m, string $u, array $opcoes) use (&$corpos): MockResponse {
            $corpos[] = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR);

            return self::ok();
        });

        self::provedor($http)->completar($this->pedido());
        (new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, 'low'))->completar($this->pedido());

        self::assertArrayNotHasKey('output_config', $corpos[0]);
        self::assertSame(['effort' => 'low'], $corpos[1]['output_config']);

        self::assertTrue((new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, ' Medium '))->estaConfigurado());
        // Valor desconhecido não derruba a montagem (a extensão Twig da IA está em toda página): só deixa dormente.
        self::assertFalse((new ProvedorAnthropic($http, 'sk', 'modelo', 'https://api.exemplo.test', 60.0, 'turbo'))->estaConfigurado());
    }

    #[TestDox('Falha de rede ou timeout é transitória, e a chave não vai para a exceção nem para o "previous"')]
    public function testRede(): void
    {
        // O cliente HTTP real cita o cabeçalho na mensagem quando o recusa; o Messenger serializa o `previous` na fila.
        $http = new MockHttpClient(static fn () => throw new TransportException('Invalid header: "x-api-key: sk-ant-SEGREDO"'));

        try {
            self::provedor($http, 'sk-ant-SEGREDO')->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertTrue($e->transitoria);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SEGREDO', $e->getMessage());
        }
    }

    #[TestDox('4xx de proxy sem JSON (401, 413) é definitivo: repetir não melhora')]
    public function testQuatroCentosSemJson(): void
    {
        foreach ([401, 413] as $status) {
            $http = new MockHttpClient(new MockResponse('<html>erro</html>', ['http_code' => $status, 'response_headers' => ['content-type' => 'text/html']]));
            try {
                self::provedor($http)->completar($this->pedido());
                self::fail('Deveria falhar.');
            } catch (FalhaDoProvedorException $e) {
                self::assertFalse($e->transitoria, (string) $status);
                self::assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    #[TestDox('Papel desconhecido no pedido é falha definitiva (a análise não fica presa em "processando")')]
    public function testPapelDesconhecido(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('não deveria chamar a rede'));
        $pedido = new PedidoDeLinguagem('s', [['papel' => 'sistema', 'conteudo' => 'a']], 100, 0.0, true, 'x');

        $this->expectException(FalhaDoProvedorException::class);
        self::provedor($http)->completar($pedido);
    }

    #[TestDox('Página de erro em HTML (proxy) é transitória, sem copiar o corpo')]
    public function testCorpoQueNaoEJson(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>Bad gateway ' . self::SEGREDO_DO_PROCESSO . '</html>', ['http_code' => 502, 'response_headers' => ['content-type' => 'text/html']]));

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertTrue($e->transitoria);
            self::assertStringNotContainsString(self::SEGREDO_DO_PROCESSO, $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function respostasInuteis(): iterable
    {
        yield 'recusa do modelo'          => [['stop_reason' => 'refusal']];
        yield 'sem bloco de texto'        => [['content' => [['type' => 'thinking', 'thinking' => 'x']]]];
        yield 'texto vazio'               => [['content' => [['type' => 'text', 'text' => '   ']]]];
    }

    /** @param array<string, mixed> $corpo */
    #[DataProvider('respostasInuteis')]
    #[TestDox('Resposta que não serve ($_dataName) é falha definitiva — repetir dá o mesmo')]
    public function testRespostasInuteis(array $corpo): void
    {
        try {
            self::provedor(new MockHttpClient(self::ok($corpo)))->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertFalse($e->transitoria);
        }
    }

    #[TestDox('Resposta cortada no limite de tokens: repete UMA vez com o dobro de max_tokens e devolve a segunda')]
    public function testCortadaNoLimiteRepeteComODobro(): void
    {
        $tetos = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$tetos): MockResponse {
            $tetos[] = json_decode((string) $opcoes['body'], true, 512, JSON_THROW_ON_ERROR)['max_tokens'];

            return count($tetos) === 1 ? self::ok(['stop_reason' => 'max_tokens']) : self::ok();
        });

        $resposta = self::provedor($http)->completar($this->pedido());

        self::assertSame('{"resumo":"ok"}', $resposta->texto);
        self::assertCount(2, $tetos);
        self::assertSame($tetos[0] * 2, $tetos[1]);
    }

    #[TestDox('Cortada no limite também na segunda tentativa: falha definitiva, sem terceira chamada')]
    public function testCortadaDuasVezesFalha(): void
    {
        $chamadas = 0;
        $http = new MockHttpClient(function () use (&$chamadas): MockResponse {
            ++$chamadas;

            return self::ok(['stop_reason' => 'max_tokens']);
        });

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertFalse($e->transitoria);
            self::assertStringContainsString('cortada no limite de tokens', $e->getMessage());
        }
        self::assertSame(2, $chamadas);
    }

    #[TestDox('Id de requisição fora do formato não entra na mensagem')]
    public function testIdDeRequisicaoSuspeito(): void
    {
        $http = new MockHttpClient(new MockResponse('{"type":"error","error":{"type":"api_error"}}', ['http_code' => 500, 'response_headers' => ['content-type' => 'application/json', 'request-id' => '<script>x</script>']]));

        try {
            self::provedor($http)->completar($this->pedido());
            self::fail('Deveria falhar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertStringNotContainsString('<script>', $e->getMessage());
        }
    }
}
