<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * O IP do cliente em produção não pode ser forjado pelo X-Forwarded-For.
 *
 * Os limitadores por IP (login, cadastro, convite, recuperação de senha, PDF da política) e a auditoria usam
 * getClientIp(). Em produção o nginx fala com o PHP-FPM por FastCGI, e em FastCGI o REMOTE_ADDR que o PHP recebe
 * é o do CLIENTE (o nginx o repassa pelo fastcgi_params), não o do nginx. Por isso:
 * - SYMFONY_TRUSTED_PROXIES com REMOTE_ADDR confiava no próprio cliente e aceitava o IP que ele forjasse na lista;
 * - o nginx passa a mandar HTTP_X_FORWARDED_FOR = $remote_addr, e o exemplo de produção não confia em ninguém
 *   (127.0.0.1 só para a variável existir). Qualquer uma das duas metades sozinha já fecha o furo.
 * Medido de ponta a ponta em 19/10/2026 com nginx:alpine na frente do PHP-FPM do dev.
 */
#[CoversNothing]
final class IpDoClienteAtrasDoNginxTest extends TestCase
{
    private const IP_REAL = '203.0.113.9';
    private const GATEWAY_DOCKER = '172.18.0.1';
    private const CABECALHOS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;
    private const CONFIA_NO_CLIENTE = ['127.0.0.1', 'REMOTE_ADDR'];
    private const SEM_PROXY = ['127.0.0.1'];

    /** @var array<string, mixed> */
    private array $servidorOriginal;

    protected function setUp(): void
    {
        $this->servidorOriginal = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->servidorOriginal;
        Request::setTrustedProxies([], self::CABECALHOS);
    }

    #[TestDox('o nginx de produção passa ao PHP só o $remote_addr, nunca a lista do cliente')]
    public function testNginxDeProducaoSobrescreveOXForwardedFor(): void
    {
        $conf = (string) file_get_contents(self::raiz() . '/nginx/conf.d/nginx.prod.conf');

        self::assertMatchesRegularExpression('/^\s*fastcgi_param HTTP_X_FORWARDED_FOR \$remote_addr;/m', $conf);
        self::assertDoesNotMatchRegularExpression('/^\s*fastcgi_param HTTP_X_FORWARDED_FOR \$proxy_add_x_forwarded_for/m', $conf);
    }

    #[TestDox('o exemplo de produção não confia em REMOTE_ADDR nem em private_ranges, e o DEPLOY.md também não sugere')]
    public function testConfiguracaoDeProducaoNaoConfiaNoCliente(): void
    {
        $exemplo = (string) file_get_contents(self::raiz() . '/.env.prod.example');
        self::assertMatchesRegularExpression('/^SYMFONY_TRUSTED_PROXIES=127\.0\.0\.1$/m', $exemplo);

        $deploy = (string) file_get_contents(self::raiz() . '/DEPLOY.md');
        self::assertStringContainsString('`SYMFONY_TRUSTED_PROXIES=127.0.0.1`', $deploy);
        self::assertDoesNotMatchRegularExpression('/SYMFONY_TRUSTED_PROXIES[^\n]*\(ex: `private_ranges`\)/', $deploy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tentativasDeForjar(): iterable
    {
        yield 'um IP forjado' => ['1.2.3.4'];
        yield 'vários IPs forjados' => ['1.2.3.4, 10.0.0.1, 127.0.0.1'];
    }

    #[DataProvider('tentativasDeForjar')]
    #[TestDox('ANTES (nginx com $proxy_add_x_forwarded_for e REMOTE_ADDR confiável): o IP forjado vence ($_dataName)')]
    public function testConfiguracaoAntigaDeixavaForjar(string $forjado): void
    {
        $ip = $this->ipVisto(self::CONFIA_NO_CLIENTE, self::IP_REAL, $forjado . ', ' . self::IP_REAL);

        self::assertNotSame(self::IP_REAL, $ip, 'a configuração antiga aceitava o IP forjado');
    }

    #[DataProvider('tentativasDeForjar')]
    #[TestDox('DEPOIS, só o nginx novo: o IP é o real mesmo com REMOTE_ADDR ainda confiável ($_dataName)')]
    public function testNginxNovoFechaSozinho(string $forjado): void
    {
        // O nginx novo ignora o cabeçalho do cliente: X-Forwarded-For = $remote_addr.
        self::assertSame(self::IP_REAL, $this->ipVisto(self::CONFIA_NO_CLIENTE, self::IP_REAL, self::IP_REAL));
    }

    #[DataProvider('tentativasDeForjar')]
    #[TestDox('DEPOIS, só a variável nova: o IP é o real mesmo com o nginx antigo ($_dataName)')]
    public function testVariavelNovaFechaSozinha(string $forjado): void
    {
        self::assertSame(self::IP_REAL, $this->ipVisto(self::SEM_PROXY, self::IP_REAL, $forjado . ', ' . self::IP_REAL));
    }

    #[TestDox('o IP real continua chegando: clientes diferentes têm chaves diferentes no limitador')]
    public function testClientesDiferentesNaoViramUmSoIp(): void
    {
        self::assertSame('198.51.100.7', $this->ipVisto(self::SEM_PROXY, '198.51.100.7', '198.51.100.7'));
        self::assertSame(self::IP_REAL, $this->ipVisto(self::SEM_PROXY, self::IP_REAL, self::IP_REAL));
    }

    #[TestDox('private_ranges também deixa forjar quando o nginx vê o gateway do Docker (IPv6 ou acesso pelo host)')]
    public function testPrivateRangesDeixaForjarPeloGatewayDoDocker(): void
    {
        $privadas = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

        self::assertSame('1.2.3.4', $this->ipVisto($privadas, self::GATEWAY_DOCKER, '1.2.3.4, ' . self::GATEWAY_DOCKER));
        self::assertSame(self::GATEWAY_DOCKER, $this->ipVisto(self::SEM_PROXY, self::GATEWAY_DOCKER, '1.2.3.4, ' . self::GATEWAY_DOCKER));
    }

    /**
     * Como o PHP-FPM vê a requisição atrás do nginx: REMOTE_ADDR é o $remote_addr do nginx (o cliente). O Symfony
     * troca a palavra REMOTE_ADDR por $_SERVER['REMOTE_ADDR'] ao configurar os proxies, por isso o $_SERVER
     * precisa estar montado antes.
     *
     * @param list<string> $proxies
     */
    private function ipVisto(array $proxies, string $remoteAddr, string $xff): ?string
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        Request::setTrustedProxies($proxies, self::CABECALHOS);

        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $remoteAddr, 'HTTP_X_FORWARDED_FOR' => $xff])->getClientIp();
    }

    private static function raiz(): string
    {
        return dirname(__DIR__, 4);
    }
}
