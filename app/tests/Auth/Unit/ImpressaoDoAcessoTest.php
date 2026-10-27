<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\ImpressaoDoAcesso;
use App\Auth\Service\NavegadorDoAcesso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImpressaoDoAcesso::class)]
#[CoversClass(NavegadorDoAcesso::class)]
final class ImpressaoDoAcessoTest extends TestCase
{
    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.71 Safari/537.36';
    private const CHROME_WINDOWS_PATCH = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.109 Safari/537.36';
    private const CHROME_WINDOWS_121 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

    private function impressao(string $ua, ?string $ip, string $segredo = 'segredo'): string
    {
        return (new ImpressaoDoAcesso($segredo))->calcular(NavegadorDoAcesso::deUserAgent($ua), $ip);
    }

    #[TestDox('mesma faixa /24 e mesmo navegador dão a mesma impressão, mesmo com IP e versão de correção diferentes')]
    public function testMesmaFaixaMesmaImpressao(): void
    {
        self::assertSame(
            $this->impressao(self::CHROME_WINDOWS, '200.10.20.5'),
            $this->impressao(self::CHROME_WINDOWS_PATCH, '200.10.20.250'),
        );
    }

    #[TestDox('mesma família com versão maior diferente (atualização do navegador) dá a mesma impressão')]
    public function testVersaoMaiorNaoMuda(): void
    {
        self::assertSame(
            $this->impressao(self::CHROME_WINDOWS, '200.10.20.5'),
            $this->impressao(self::CHROME_WINDOWS_121, '200.10.20.5'),
        );
    }

    #[TestDox('outra faixa, outro navegador ou outro segredo dão outra impressão')]
    public function testDiferencas(): void
    {
        $base = $this->impressao(self::CHROME_WINDOWS, '200.10.20.5');

        self::assertNotSame($base, $this->impressao(self::CHROME_WINDOWS, '200.10.21.5'));
        self::assertNotSame($base, $this->impressao(self::FIREFOX_LINUX, '200.10.20.5'));
        self::assertNotSame($base, $this->impressao(self::CHROME_WINDOWS, '200.10.20.5', 'outro'));
    }

    #[TestDox('a impressão tem 64 hex e não contém o IP cru nem o User-Agent')]
    public function testSemIpCru(): void
    {
        $r = $this->impressao(self::CHROME_WINDOWS, '200.10.20.5');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r);
        self::assertStringNotContainsString('200.10.20', $r);
        self::assertStringNotContainsString('Chrome', $r);
    }

    #[TestDox('IPv6 usa a faixa /48; IPv4 mapeado em IPv6 vale como o IPv4; IP ilegível não derruba')]
    public function testIpv6(): void
    {
        self::assertSame(
            $this->impressao(self::CHROME_WINDOWS, '2001:db8:abcd:1::1'),
            $this->impressao(self::CHROME_WINDOWS, '2001:db8:abcd:ffff::9'),
        );
        self::assertNotSame(
            $this->impressao(self::CHROME_WINDOWS, '2001:db8:abcd:1::1'),
            $this->impressao(self::CHROME_WINDOWS, '2001:db8:abce:1::1'),
        );
        self::assertSame(
            $this->impressao(self::CHROME_WINDOWS, '200.10.20.5'),
            $this->impressao(self::CHROME_WINDOWS, '::ffff:200.10.20.77'),
        );
        self::assertSame($this->impressao(self::CHROME_WINDOWS, null), $this->impressao(self::CHROME_WINDOWS, 'lixo'));
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function agentes(): iterable
    {
        yield 'Chrome no Windows' => [self::CHROME_WINDOWS, 'Chrome 120', 'Windows'];
        yield 'Firefox no Linux' => [self::FIREFOX_LINUX, 'Firefox 121', 'Linux'];
        yield 'Edge' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91', 'Edge 120', 'Windows'];
        yield 'Safari no iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1', 'Safari 17', 'iOS'];
        yield 'Chrome no Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36', 'Chrome 120', 'Android'];
        yield 'Safari no Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15', 'Safari 17', 'macOS'];
        yield 'desconhecido' => ['curl/8.0', null, null];
        yield 'vazio' => ['', null, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agentes')]
    #[TestDox('lê navegador e sistema: $_dataName')]
    public function testLeNavegador(string $ua, ?string $navegador, ?string $sistema): void
    {
        $n = NavegadorDoAcesso::deUserAgent($ua);

        self::assertSame($navegador, $n->rotuloDoNavegador());
        self::assertSame($sistema, $n->sistema);
    }
}
