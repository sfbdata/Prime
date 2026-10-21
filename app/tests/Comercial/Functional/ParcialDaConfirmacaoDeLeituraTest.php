<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Service\RastreioDaLeitura;
use App\Comercial\Twig\RastreioDaLeituraExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * A chave "Confirmação de leitura" da barra de envio (F3g; desenho 05 VS 21.10.26, L291 e L656; decisão 4): entre o
 * segundo vão e o escudo de LGPD; desligada e sem clique com o rastreio global desligado (hoje), com a explicação.
 */
#[CoversClass(RastreioDaLeituraExtension::class)]
#[CoversClass(\App\Comercial\Twig\RastreioDaLeituraRuntime::class)]
final class ParcialDaConfirmacaoDeLeituraTest extends KernelTestCase
{
    #[TestDox('Hoje (rastreio global desligado): "Sem confirmação de leitura", desabilitada, valor 0 e a explicação da Política no title')]
    public function testDesligadaComAExplicacao(): void
    {
        $chave = $this->renderizar()->filter('.mc-cp-lado > .mc-cp-barra > .mc-cp-vao + button.mc-cp-leitura + .mc-cp-lgpd');
        self::assertCount(1, $chave, 'entre o segundo vão e o escudo de LGPD, como no dc');

        $chave = $this->renderizar()->filter('button.mc-cp-leitura');
        self::assertSame('Sem confirmação de leitura', trim($chave->text()));
        self::assertNotNull($chave->attr('disabled'));
        self::assertSame(['0', 'false'], [$chave->attr('data-mc-cp-rastrear'), $chave->attr('aria-pressed')]);
        self::assertStringContainsString('Política de Privacidade', (string) $chave->attr('title'));
        self::assertCount(1, $chave->filter('i.bi.bi-check2-all'));
    }

    #[TestDox('Com as duas chaves globais ligadas: começa ligada, como no dc ("Confirmação de leitura", valor 1, com clique)')]
    public function testLiberadaComecaLigada(): void
    {
        $chave = $this->renderizar(new RastreioDaLeitura(true, true))->filter('button.mc-cp-leitura');

        self::assertSame('Confirmação de leitura', trim($chave->text()));
        self::assertNull($chave->attr('disabled'));
        self::assertSame(['1', 'true'], [$chave->attr('data-mc-cp-rastrear'), $chave->attr('aria-pressed')]);
        self::assertStringContainsString('is-ligada', (string) $chave->attr('class'));
        self::assertSame('Rastreando leitura (✓✓). Clique para somente enviar', $chave->attr('title'));
    }

    #[TestDox('O script da chave vem fora da barra (no parcial dos KPIs)')]
    public function testScript(): void
    {
        $doc = $this->renderizar();

        self::assertCount(1, $doc->filter('script[src*="js/master/comercial-leitura.js"]'));
        self::assertCount(0, $doc->filter('.mc-cp-barra script'));
    }

    private function renderizar(?RastreioDaLeitura $rastreio = null): Crawler
    {
        self::bootKernel();
        if ($rastreio !== null) {
            static::getContainer()->set(RastreioDaLeitura::class, $rastreio);
        }
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });

        return new Crawler('<!doctype html><html><body>' . static::getContainer()->get(Environment::class)->render('master/_comercial.html.twig') . '</body></html>');
    }
}
