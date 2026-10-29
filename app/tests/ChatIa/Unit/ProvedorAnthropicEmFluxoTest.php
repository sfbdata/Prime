<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Service\ProvedorAnthropic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** `ProvedorAnthropic::transmitir` e o modelo por pedido, com o `MockHttpClient`: nenhuma chamada real. */
#[CoversClass(ProvedorAnthropic::class)]
final class ProvedorAnthropicEmFluxoTest extends TestCase
{
    private const CHAVE = 'sk-ant-chave-secreta-123';
    private const SEGREDO = 'TEXTO-SIGILOSO-DA-PERGUNTA';
    private const SSE = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-sonnet-5-5\",\"usage\":{\"input_tokens\":25}}}\n\n"
        . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Olá\"}}\n\n"
        . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\", doutor\"}}\n\n"
        . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":7}}\n\n"
        . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

    private function pedido(?string $modelo = null): PedidoDeLinguagem
    {
        return new PedidoDeLinguagem('Sistema.', [['papel' => 'usuario', 'conteudo' => self::SEGREDO]], 500, 0.2, false, 'chat-livre-v1', [], $modelo);
    }

    private function provedor(MockHttpClient $http): ProvedorAnthropic
    {
        return new ProvedorAnthropic($http, self::CHAVE, 'modelo-da-plataforma', 'https://api.exemplo.test');
    }

    #[TestDox('Sucesso: rende os trechos na ordem, devolve o uso real e pede stream com o modelo do pedido')]
    public function testSucesso(): void
    {
        $visto = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$visto): MockResponse {
            $visto = ['url' => $url, 'corpo' => json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR)];

            return new MockResponse(self::SSE, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $fluxo = $this->provedor($http)->transmitir($this->pedido('claude-opus-5-5'));
        $trechos = iterator_to_array($fluxo, false);
        $uso = $fluxo->getReturn();

        self::assertSame(['Olá', ', doutor'], $trechos);
        self::assertInstanceOf(UsoDaTransmissao::class, $uso);
        self::assertSame(25, $uso->tokensEntrada);
        self::assertSame(7, $uso->tokensSaida);
        self::assertSame('claude-sonnet-5-5', $uso->modelo);
        self::assertSame('https://api.exemplo.test/v1/messages', $visto['url']);
        self::assertTrue($visto['corpo']['stream']);
        self::assertSame('claude-opus-5-5', $visto['corpo']['model']);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function statusDeErro(): iterable
    {
        yield '401 definitivo' => [401, false];
        yield '400 definitivo' => [400, false];
        yield '429 transitório' => [429, true];
        yield '500 transitório' => [500, true];
        yield '503 transitório' => [503, true];
        yield '529 transitório' => [529, true];
    }

    #[TestDox('HTTP $status: vira FalhaDoProvedorException (transitória: $transitoria) sem vazar corpo, chave nem pergunta')]
    #[DataProvider('statusDeErro')]
    public function testErroPorStatus(int $status, bool $transitoria): void
    {
        $corpoDoErro = json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key ' . self::CHAVE . ' ' . self::SEGREDO]], JSON_THROW_ON_ERROR);
        $http = new MockHttpClient(new MockResponse($corpoDoErro, ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]));

        try {
            iterator_to_array($this->provedor($http)->transmitir($this->pedido()), false);
            self::fail('Deveria lançar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertSame($transitoria, $e->transitoria);
            self::assertFalse($e->deRede, 'Erro de status do provedor não é falha de rede (a tela diz "sobrecarregada").');
            self::assertStringContainsString((string) $status, $e->getMessage());
            self::assertStringNotContainsString(self::CHAVE, $e->getMessage());
            self::assertStringNotContainsString(self::SEGREDO, $e->getMessage());
            self::assertStringNotContainsString('invalid x-api-key', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('Falha de rede no meio da abertura: transitória, sem a chave na mensagem')]
    public function testFalhaDeRede(): void
    {
        $http = new MockHttpClient(static fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('x-api-key: ' . self::CHAVE));

        try {
            iterator_to_array($this->provedor($http)->transmitir($this->pedido()), false);
            self::fail('Deveria lançar.');
        } catch (FalhaDoProvedorException $e) {
            self::assertTrue($e->transitoria);
            self::assertTrue($e->deRede, 'Falha de transporte vira "demorou demais" na tela.');
            self::assertStringNotContainsString(self::CHAVE, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    #[TestDox('completar(): modelo nulo no pedido usa o da plataforma; modelo no pedido vale sobre ele')]
    public function testCompletarComEPedidoSemModelo(): void
    {
        $modelos = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$modelos): MockResponse {
            $modelos[] = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR)['model'];

            return new MockResponse(json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $provedor = $this->provedor($http);

        $provedor->completar($this->pedido());
        $provedor->completar($this->pedido('claude-haiku-5-5'));

        self::assertSame(['modelo-da-plataforma', 'claude-haiku-5-5'], $modelos);
    }
}
