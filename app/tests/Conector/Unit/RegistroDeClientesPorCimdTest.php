<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Conector\Service\HostsDeClientePermitidos;
use App\Conector\Service\ListaDeRedirects;
use App\Conector\Service\RegistroDeClientesPorCimd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O registro do cliente por CIMD (spec mcp-remoto-oauth 3.4, 9.5 e 9.6; teste 12.12), com o repositório do domínio
 * simulado e a busca de verdade sobre um {@see MockHttpClient}. A gravação no banco (o `INSERT ... ON CONFLICT` e a
 * corrida) está em `Integration/RegistroDeClientesPorCimdNoBancoTest`.
 */
#[CoversClass(RegistroDeClientesPorCimd::class)]
final class RegistroDeClientesPorCimdTest extends TestCase
{
    use CenarioDoConector;
    use MontaBuscaCimd;

    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const RETORNO = 'http://127.0.0.1:54321/callback';

    /** @var ClienteDoConectorRepository&MockObject */
    private ClienteDoConectorRepository $clientes;
    private AbstractLogger $logger;
    private MockClock $relogio;

    protected function setUp(): void
    {
        $this->clientes = $this->createMock(ClienteDoConectorRepository::class);
        $this->logger = self::loggerQueGuarda();
        $this->relogio = self::relogio();
    }

    /** @param list<string> $hosts */
    private function sut(MockHttpClient $http, array $hosts = ['claude.ai', 'chatgpt.com']): RegistroDeClientesPorCimd
    {
        return new RegistroDeClientesPorCimd($this->clientes, $this->relogio, new HostsDeClientePermitidos($hosts), new ListaDeRedirects(), self::busca($http, $this->relogio), $this->logger);
    }

    /** Cliente gravado com o documento do Claude Code (redirects de loopback sem porta). */
    private static function gravado(string $validoAte = '2026-11-16 13:00:00', string $url = self::URL): ClienteDoConector
    {
        $cliente = self::clienteCimd($url);
        $cliente->renovarMetadados('Claude Code', ['http://localhost/callback', 'http://127.0.0.1/callback'], self::em('2026-11-16 11:00:00'), self::em($validoAte));

        return $cliente;
    }

    private function recusa(RegistroDeClientesPorCimd $sut, string $motivo, string $redirect = self::RETORNO, string $clientId = self::URL, ?string $host = 'claude.ai'): void
    {
        try {
            $sut->resolver($clientId, $redirect);
            self::fail('aceitou: ' . $motivo);
        } catch (ClienteRecusado $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertSame($host, $e->host);
        }
    }

    /** O único registro do log: a recusa, só com motivo e host. */
    private function assertLogDaRecusa(string $motivo, ?string $host): void
    {
        $registros = $this->logger->registros ?? [];
        self::assertCount(1, $registros);
        self::assertSame(['motivo' => $motivo, 'host' => $host], $registros[0]['contexto']);
        self::assertStringNotContainsString('oauth', $registros[0]['mensagem']);
        self::assertStringNotContainsString('callback', $registros[0]['mensagem']);
    }

    #[TestDox('Cache válido: devolve o cliente gravado sem buscar o documento; loopback com qualquer porta passa')]
    public function testCacheValidoNaoBusca(): void
    {
        $cliente = self::gravado();
        $this->clientes->method('buscarPorIdentificador')->willReturn($cliente);
        $this->clientes->expects(self::never())->method('salvar');
        $this->clientes->expects(self::never())->method('inserirSeAusente');
        $sut = $this->sut(self::semRede());

        self::assertSame($cliente, $sut->resolver(self::URL, 'http://127.0.0.1:54321/callback'));
        self::assertSame($cliente, $sut->resolver(self::URL, 'http://localhost:3000/callback'));
        self::assertSame($cliente, $sut->resolver(self::URL, 'http://127.0.0.1/callback'));
        self::assertSame([], $this->logger->registros ?? []);
    }

    #[TestDox('Cliente novo: busca, insere pelo INSERT sem conflito e devolve o relido, com a validade do cache')]
    public function testClienteNovoBuscaEGrava(): void
    {
        $relido = self::gravado();
        $this->clientes->method('buscarPorIdentificador')->willReturnOnConsecutiveCalls(null, $relido);
        $inserido = null;
        $this->clientes->expects(self::once())->method('inserirSeAusente')->willReturnCallback(static function (ClienteDoConector $c) use (&$inserido): bool {
            $inserido = $c;

            return true;
        });
        $http = new MockHttpClient(self::resposta(self::documento(self::URL, nome: 'Claude Code'), 200, ['Cache-Control: max-age=7200']));

        self::assertSame($relido, $this->sut($http)->resolver(self::URL, self::RETORNO));
        self::assertInstanceOf(ClienteDoConector::class, $inserido);
        self::assertSame(self::URL, $inserido->getIdentificador());
        self::assertSame('Claude Code', $inserido->getNome());
        self::assertSame(['http://localhost/callback', 'http://127.0.0.1/callback'], $inserido->getRedirectUris());
        self::assertEquals(self::em('2026-11-16 12:00:00'), $inserido->getMetadadosBuscadosEm());
        self::assertEquals(self::em('2026-11-16 14:00:00'), $inserido->getMetadadosValidosAte());
        self::assertSame(1, $http->getRequestsCount());
    }

    #[TestDox('Corrida de unique: o INSERT não grava (outro pedido gravou antes) e o registro devolve o relido')]
    public function testCorridaDeUniqueRele(): void
    {
        $doOutroPedido = self::gravado();
        $this->clientes->method('buscarPorIdentificador')->willReturnOnConsecutiveCalls(null, $doOutroPedido);
        $this->clientes->expects(self::once())->method('inserirSeAusente')->willReturn(false);

        self::assertSame($doOutroPedido, $this->sut(new MockHttpClient(self::resposta(self::documento(self::URL))))->resolver(self::URL, self::RETORNO));
    }

    #[TestDox('Na corrida, o cliente relido desativado é recusado')]
    public function testCorridaComClienteDesativado(): void
    {
        $desativado = self::gravado();
        $desativado->desativar();
        $this->clientes->method('buscarPorIdentificador')->willReturnOnConsecutiveCalls(null, $desativado);
        $this->clientes->method('inserirSeAusente')->willReturn(false);

        $this->recusa($this->sut(new MockHttpClient(self::resposta(self::documento(self::URL)))), ClienteRecusado::MOTIVO_CLIENTE_INATIVO);
    }

    #[TestDox('Cache vencido (inclusive no instante exato) ou sem validade: busca de novo, renova e salva')]
    public function testCacheVencidoBuscaERenova(): void
    {
        foreach (['2026-11-16 12:00:00', '2026-11-16 11:59:59', null] as $validoAte) {
            $cliente = $validoAte === null ? self::clienteCimd(self::URL) : self::gravado($validoAte);
            $clientes = $this->createMock(ClienteDoConectorRepository::class);
            $clientes->method('buscarPorIdentificador')->willReturn($cliente);
            $clientes->expects(self::once())->method('salvar')->with($cliente, true);
            $clientes->expects(self::never())->method('inserirSeAusente');
            $this->clientes = $clientes;
            $http = new MockHttpClient(self::resposta(self::documento(self::URL, ['http://127.0.0.1/callback'], 'Claude Code novo'), 200, ['Cache-Control: no-store']));

            self::assertSame($cliente, $this->sut($http)->resolver(self::URL, self::RETORNO));
            self::assertSame('Claude Code novo', $cliente->getNome());
            self::assertSame(['http://127.0.0.1/callback'], $cliente->getRedirectUris());
            self::assertEquals(self::em('2026-11-16 12:05:00'), $cliente->getMetadadosValidosAte());
            self::assertSame(1, $http->getRequestsCount());
        }
    }

    #[TestDox('A segunda chamada dentro do max-age não busca; depois dele, busca')]
    public function testCacheRespeitaMaxAge(): void
    {
        $gravado = null;
        $this->clientes->method('buscarPorIdentificador')->willReturnCallback(static function () use (&$gravado): ?ClienteDoConector {
            return $gravado;
        });
        $this->clientes->method('inserirSeAusente')->willReturnCallback(static function (ClienteDoConector $c) use (&$gravado): bool {
            $gravado = $c;

            return true;
        });
        $http = new MockHttpClient(static fn (): MockResponse => self::resposta(self::documento(self::URL), 200, ['Cache-Control: max-age=600']));
        $sut = $this->sut($http);

        $sut->resolver(self::URL, self::RETORNO);
        $this->relogio->modify('+599 seconds');
        $sut->resolver(self::URL, self::RETORNO);
        self::assertSame(1, $http->getRequestsCount());

        $this->relogio->modify('+1 second');
        $sut->resolver(self::URL, self::RETORNO);
        self::assertSame(2, $http->getRequestsCount());
    }

    #[TestDox('client_id do documento diferente da URL buscada: recusa e não grava')]
    public function testClientIdDoDocumentoDiferente(): void
    {
        $this->clientes->method('buscarPorIdentificador')->willReturn(null);
        $this->clientes->expects(self::never())->method('inserirSeAusente');

        $this->recusa($this->sut(new MockHttpClient(self::resposta(self::documento('https://claude.ai/oauth/outro')))), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
        $this->assertLogDaRecusa(ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO, 'claude.ai');
    }

    #[TestDox('redirect_uri na lista do servidor mas fora do documento: recusa')]
    public function testRedirectForaDoDocumento(): void
    {
        $this->clientes->method('buscarPorIdentificador')->willReturn(self::gravado());

        $this->recusa($this->sut(self::semRede()), ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'https://claude.ai/api/mcp/auth_callback');
        $this->assertLogDaRecusa(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'claude.ai');
    }

    #[TestDox('redirect_uri no documento mas fora da lista do servidor: recusa, sem buscar')]
    public function testRedirectNoDocumentoForaDaLista(): void
    {
        $cliente = self::clienteCimd(self::URL);
        $cliente->renovarMetadados('Claude Code', ['https://evil.example/callback', 'http://localhost.evil.com/callback'], self::em('2026-11-16 11:00:00'), self::em('2026-11-16 13:00:00'));
        $this->clientes->method('buscarPorIdentificador')->willReturn($cliente);
        $sut = $this->sut(self::semRede());

        $this->recusa($sut, ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'https://evil.example/callback');
        $this->recusa($sut, ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'http://localhost.evil.com/callback');
        $this->recusa($sut, ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'http://localhost.evil.com:8080/callback');
    }

    #[TestDox('Host do client_id fora da configuração: recusa sem buscar nem ler o banco, e o log leva só o host')]
    public function testHostForaDaConfiguracao(): void
    {
        $this->clientes->expects(self::never())->method('buscarPorIdentificador');

        $this->recusa($this->sut(self::semRede()), ClienteRecusado::MOTIVO_HOST_NAO_PERMITIDO, clientId: 'https://evil.example/oauth/client.json', host: 'evil.example');
        $this->assertLogDaRecusa(ClienteRecusado::MOTIVO_HOST_NAO_PERMITIDO, 'evil.example');
    }

    #[TestDox('Trocar a configuração muda a lista: tirar o host corta até o cliente já gravado; pôr outro libera')]
    public function testConfiguracaoMudaALista(): void
    {
        $this->clientes->method('buscarPorIdentificador')->willReturn(self::gravado());

        $this->recusa($this->sut(self::semRede(), ['chatgpt.com']), ClienteRecusado::MOTIVO_HOST_NAO_PERMITIDO);
        self::assertSame(self::URL, $this->sut(self::semRede(), ['CLAUDE.AI'])->resolver(self::URL, self::RETORNO)->getIdentificador());
    }

    /** @return iterable<string, array{string}> */
    public static function clientIdsInvalidos(): iterable
    {
        yield 'http' => ['http://claude.ai/oauth/claude-code-client-metadata'];
        yield 'HTTPS em maiúsculas' => ['HTTPS://claude.ai/oauth/claude-code-client-metadata'];
        yield 'sem esquema' => ['claude.ai/oauth/claude-code-client-metadata'];
        yield 'texto qualquer' => ['claude-code'];
        yield 'vazio' => [''];
        yield 'sem caminho' => ['https://claude.ai'];
        yield 'só a barra' => ['https://claude.ai/'];
        yield 'com fragmento' => ['https://claude.ai/oauth/claude-code-client-metadata#x'];
        yield 'com fragmento vazio' => ['https://claude.ai/oauth/claude-code-client-metadata#'];
        yield 'com query' => ['https://claude.ai/oauth/claude-code-client-metadata?v=1'];
        yield 'com usuário' => ['https://user@claude.ai/oauth/claude-code-client-metadata'];
        yield 'com usuário e senha' => ['https://user:senha@claude.ai/oauth/claude-code-client-metadata'];
        yield 'com porta' => ['https://claude.ai:8443/oauth/claude-code-client-metadata'];
        yield 'com ..' => ['https://claude.ai/oauth/../claude-code-client-metadata'];
        yield 'com .' => ['https://claude.ai/./oauth/claude-code-client-metadata'];
        yield 'com .. codificado' => ['https://claude.ai/oauth/%2e%2e/claude-code-client-metadata'];
        yield 'com barra invertida' => ['https://claude.ai\\@evil.example/x'];
        yield 'com espaço' => ['https://claude.ai/oauth/claude code'];
        yield 'com quebra de linha' => ["https://claude.ai/oauth/claude-code-client-metadata\n"];
        yield 'longo demais' => ['https://claude.ai/' . str_repeat('a', 2000)];
    }

    #[DataProvider('clientIdsInvalidos')]
    #[TestDox('client_id fora da forma CIMD recusa sem buscar: $_dataName')]
    public function testClientIdInvalido(string $clientId): void
    {
        $this->clientes->expects(self::never())->method('buscarPorIdentificador');

        try {
            $this->sut(self::semRede())->resolver($clientId, self::RETORNO);
            self::fail('aceitou o client_id');
        } catch (ClienteRecusado $e) {
            self::assertSame(ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO, $e->motivo);
        }
    }

    #[TestDox('Cliente desativado é recusado sem buscar, mesmo com o cache vencido')]
    public function testDesativado(): void
    {
        $desativado = self::gravado('2026-11-16 11:00:00');
        $desativado->desativar();
        $this->clientes->method('buscarPorIdentificador')->willReturn($desativado);

        $this->recusa($this->sut(self::semRede()), ClienteRecusado::MOTIVO_CLIENTE_INATIVO);
    }

    #[TestDox('Documento indisponível (redirecionamento) e corpo acima do teto recusam pelo registro e não gravam')]
    public function testBuscaQueFalhaNaoGrava(): void
    {
        $this->clientes->method('buscarPorIdentificador')->willReturn(null);
        $this->clientes->expects(self::never())->method('inserirSeAusente');

        $this->recusa($this->sut(new MockHttpClient(self::resposta('', 302, ['Location: https://claude.ai/outro']))), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        $grande = self::documento(self::URL, extras: ['x' => str_repeat('a', 20000)]);
        $this->recusa($this->sut(new MockHttpClient(self::resposta($grande))), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
    }
}
