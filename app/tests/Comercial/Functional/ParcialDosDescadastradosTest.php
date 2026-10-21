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
 * Os blocos da F3c no parcial do Comercial (desenho 05 VS 21.10.26): os Descadastrados como primeiro filho do
 * `[data-mc]`, fechados (L245), e os KPIs logo antes das Automações (L325), com os textos do desenho.
 */
#[CoversNothing]
final class ParcialDosDescadastradosTest extends KernelTestCase
{
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
        $html = static::getContainer()->get(Environment::class)->render('master/_comercial.html.twig');
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    #[TestDox('Descadastrados: primeiro filho do [data-mc], fechado, com o cabeçalho do desenho e o "Limpar lista" desabilitado (decisão do dono)')]
    public function testBlocoDosDescadastrados(): void
    {
        $bloco = $this->doc->filter('[data-mc] > .mc-dsc:first-child[data-mc-dsc][hidden]');
        self::assertCount(1, $bloco);
        self::assertSame('Descadastrados', trim($bloco->filter('.mc-dsc-titulo')->text()));
        self::assertSame('clicaram em "Não quero mais receber" e saem de todas as listas automaticamente', trim($bloco->filter('.mc-dsc-sub')->text()));
        self::assertSame('Buscar e-mail', $bloco->filter('input[data-mc-dsc-busca]')->attr('placeholder'));
        self::assertSame(['Registrar pedido', 'Limpar lista', 'CSV'], $bloco->filter('.mc-dsc-cabeca > .mc-dsc-botao')->each(static fn (Crawler $b): string => trim($b->text())));
        $limpar = $bloco->filter('.mc-dsc-botao-perigo');
        self::assertNotNull($limpar->attr('disabled'));
        self::assertStringContainsString('dono', (string) $limpar->attr('title'));
        self::assertSame('Ninguém se descadastrou ainda.', trim($bloco->filter('[data-mc-dsc-vazio]')->text()));
        self::assertSame('/master/comercial/descadastrados.csv', $bloco->filter('a[data-mc-dsc-csv]')->attr('href'));
    }

    #[TestDox('KPIs: logo antes das Automações, os 4 do desenho, e o DESCADASTRADOS é o único clicável')]
    public function testKpis(): void
    {
        self::assertCount(1, $this->doc->filter('[data-mc] > .mc-kpis + script + script + section[aria-labelledby="mc-auto-titulo"]'));
        $kpis = $this->doc->filter('[data-mc] > .mc-kpis > .mc-kpi');
        self::assertSame(['CAMPANHAS', 'E-MAILS NA FILA', 'BASE DE CONTATOS', 'DESCADASTRADOS'], $kpis->each(static fn (Crawler $k): string => trim($k->filter('.mc-kpi-rotulo')->text())));
        self::assertSame(
            ['enviadas ou agendadas', 'somando todas as campanhas', 'inscritos com autorização (LGPD)', 'pediram para não receber · clique para ver'],
            $kpis->each(static fn (Crawler $k): string => trim($k->filter('.mc-kpi-desc')->text())),
        );
        self::assertCount(1, $this->doc->filter('[data-mc] > .mc-kpis > button.mc-kpi[data-mc-kpi="descadastrados"][data-mc-kpi-abrir]'));
        self::assertCount(1, $this->doc->filter('script[src*="js/master/comercial-descadastrados.js"]'));
    }
}
