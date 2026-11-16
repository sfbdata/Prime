<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\BuscaDoDocumentoCimd;
use App\Conector\Service\PoliticaDeCacheCimd;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Peças dos testes da busca CIMD sem rede: o {@see MockHttpClient} por baixo do `NoPrivateNetworkHttpClient` que a busca
 * monta, e os nomes dos hosts fixados em IPs públicos (opção `resolve`), para o teste não depender de DNS.
 */
trait MontaBuscaCimd
{
    /** IPs públicos de exemplo (o do Claude sai de 160.79.104.0/21, spec 3.4). */
    private const RESOLUCAO_PUBLICA = ['claude.ai' => '160.79.104.10', 'chatgpt.com' => '104.18.32.47'];

    private static function relogio(string $agora = '2026-11-16 12:00:00'): MockClock
    {
        return new MockClock(new \DateTimeImmutable($agora));
    }

    /**
     * @param array<string, string> $resolucao
     */
    private static function busca(MockHttpClient $http, ?MockClock $relogio = null, array $resolucao = self::RESOLUCAO_PUBLICA, int $limite = 10): BuscaDoDocumentoCimd
    {
        $limitador = new RateLimiterFactory(['id' => 'conector_cimd_teste', 'policy' => 'sliding_window', 'limit' => $limite, 'interval' => '1 minute'], new InMemoryStorage());

        return new BuscaDoDocumentoCimd($http, $limitador, new PoliticaDeCacheCimd(), $relogio ?? self::relogio(), $resolucao);
    }

    /**
     * O corpo JSON de um documento CIMD.
     *
     * @param list<string>        $redirects
     * @param array<string,mixed> $extras
     */
    private static function documento(string $clientId, array $redirects = ['http://localhost/callback', 'http://127.0.0.1/callback'], string $nome = 'Claude Code', array $extras = []): string
    {
        return json_encode(['client_id' => $clientId, 'client_name' => $nome, 'redirect_uris' => $redirects] + $extras, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<string> $cabecalhos
     */
    private static function resposta(string $corpo, int $status = 200, array $cabecalhos = ['Content-Type: application/json', 'Cache-Control: max-age=3600']): MockResponse
    {
        return new MockResponse($corpo, ['http_code' => $status, 'response_headers' => $cabecalhos]);
    }

    /** Cliente HTTP que reprova o teste se for chamado. */
    private static function semRede(): MockHttpClient
    {
        return new MockHttpClient(static function (string $metodo, string $url): MockResponse {
            throw new \LogicException('Não devia buscar ' . $url);
        });
    }

    private static function loggerQueGuarda(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{nivel: mixed, mensagem: string, contexto: array<mixed>}> */
            public array $registros = [];

            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->registros[] = ['nivel' => $level, 'mensagem' => (string) $message, 'contexto' => $context];
            }
        };
    }
}
