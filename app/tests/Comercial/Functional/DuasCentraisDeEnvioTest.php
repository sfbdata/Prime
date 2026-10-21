<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * As duas Centrais de envios (seção Comercial e modo venda da seção Indicações, F3h) com as peças da 0d: o botão
 * "Descadastrados (N)" no cabeçalho das duas (dc L251) e a chave "Confirmação de leitura" na barra das duas (dc L291),
 * cada uma dentro da própria `section[data-mc-campanha]`, onde o JS pendura `mcExtrasDoEnvio` (contrato com a 5d).
 */
#[CoversNothing]
final class DuasCentraisDeEnvioTest extends KernelTestCase
{
    private const CENTRAIS = [
        'com' => '[data-sec-externa="com"] > .mc[data-mc][data-modo="com"] > section[data-mc-campanha]',
        'venda' => '[data-sec-externa="ind"] > .mi > .mc.mc-venda[data-modo="venda"] > section[data-mc-campanha]',
    ];

    private Crawler $doc;

    protected function setUp(): void
    {
        self::bootKernel();
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
        // O mesmo arranjo das duas seções externas do painel.html.twig.
        $html = static::getContainer()->get(Environment::class)->createTemplate(
            '<div class="mp-secao-externa" data-sec-externa="com" hidden>{% include "master/_comercial.html.twig" %}</div>'
            . '<div class="mp-secao-externa" data-sec-externa="ind" hidden>{% include "master/_indicacoes.html.twig" %}</div>',
        )->render();
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    #[TestDox('O "Descadastrados (N)" está no cabeçalho das duas Centrais; o bloco é um só, na seção Comercial')]
    public function testDescadastradosNasDuas(): void
    {
        foreach (self::CENTRAIS as $modo => $central) {
            self::assertCount(1, $this->doc->filter($central . ' > .mc-cp-cabeca > button[data-mc-cp-descad] > [data-mc-cp-descad-n]'), $modo);
        }
        self::assertCount(1, $this->doc->filter('[data-mc-dsc]'), 'um bloco só: o JS o leva para a Central do clique');
        self::assertCount(1, $this->doc->filter('[data-sec-externa="com"] > .mc[data-mc] > .mc-dsc[data-mc-dsc]'));
    }

    #[TestDox('A chave "Confirmação de leitura" está na barra das duas Centrais, dentro da section[data-mc-campanha] de cada uma')]
    public function testChaveNasDuas(): void
    {
        foreach (self::CENTRAIS as $modo => $central) {
            self::assertCount(1, $this->doc->filter($central . ' .mc-cp-barra > .mc-cp-vao + button.mc-cp-leitura[data-mc-cp-rastrear] + .mc-cp-lgpd'), $modo);
        }
    }

    #[TestDox('JS: o envio mescla os ganchos globais (só na com) e os da própria raiz; a chave registra por raiz; o bloco anota a Central do clique')]
    public function testJsPorRaiz(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/';
        $campanha = (string) file_get_contents($dir . 'comercial-campanha.js');
        $leitura = (string) file_get_contents($dir . 'comercial-leitura.js');
        $descadastrados = (string) file_get_contents($dir . 'comercial-descadastrados.js');

        self::assertStringContainsString('const globais = (M.ganchos && window.McCampanha && window.McCampanha.extrasDoEnvio) || [];', $campanha);
        self::assertStringContainsString('[...globais, ...(raiz.mcExtrasDoEnvio || [])]', $campanha);
        self::assertStringContainsString("const raiz = botao.closest('[data-mc-campanha]');", $leitura);
        self::assertStringContainsString('raiz.mcExtrasDoEnvio = raiz.mcExtrasDoEnvio || [];', $leitura);
        self::assertStringNotContainsString('window.McCampanha', $leitura, 'só por raiz: a chave da seção Comercial não entra duas vezes');
        self::assertStringContainsString("e.target.closest('[data-mc-cp-descad], [data-mc-kpi-abrir]')", $descadastrados);
        self::assertStringContainsString("gatilho.closest('.mc[data-modo]')", $descadastrados);
    }
}
