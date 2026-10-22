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
 * O bloco "Datas comemorativas" no parcial do Comercial (F4; desenho 05 VS 21.10.26 L334-339), com combinador de filho
 * direto: logo depois das Automações, na raiz [data-mc], o cabeçalho do dc (ícone, título, "mensagens automáticas nas
 * datas nacionais · N ativas"), o aviso da chave geral e a lista; fora do modo venda.
 */
#[CoversNothing]
final class ParcialDasDatasComemorativasTest extends KernelTestCase
{
    private const BLOCO = '[data-mc] > section[aria-labelledby="mc-auto-titulo"] + section.mc-caixa.mc-dt[data-mc-datas]';

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

    #[TestDox('Logo depois das Automações: o cabeçalho com o ícone, o título e o subtítulo literais do dc, e as URLs das rotas')]
    public function testPosicaoECabecalho(): void
    {
        $bloco = $this->doc->filter(self::BLOCO);
        self::assertCount(1, $bloco);
        self::assertCount(1, $this->doc->filter(self::BLOCO . ' > .mc-cabeca > i.bi.bi-calendar-heart.mc-dt-ic:first-child'));
        self::assertSame('Datas comemorativas', trim($this->doc->filter(self::BLOCO . ' > .mc-cabeca > b.mc-cabeca-titulo')->text()));
        self::assertSame('mensagens automáticas nas datas nacionais · 0 ativas', preg_replace('/\s+/u', ' ', trim($this->doc->filter(self::BLOCO . ' > .mc-cabeca > .mc-cabeca-sub')->text())));
        self::assertCount(1, $this->doc->filter(self::BLOCO . ' > .mc-cabeca > .mc-cabeca-sub > [data-mc-dt-n]'));
        self::assertSame('/master/comercial/datas', $bloco->attr('data-url-lista'));
        self::assertSame('/master/comercial/datas/xchavex', $bloco->attr('data-url-alterar'));
        self::assertCount(1, $this->doc->filter(self::BLOCO . ' > .mc-dt-lista[data-mc-dt-lista][aria-busy="true"]'));
    }

    #[TestDox('O aviso da chave geral desligada nasce escondido (o JS mostra quando o servidor diz que está desligada)')]
    public function testAvisoDaChave(): void
    {
        $aviso = $this->doc->filter(self::BLOCO . ' > .mc-msg[data-mc-dt-desligada][hidden]');
        self::assertCount(1, $aviso);
        self::assertSame('O envio de campanhas está desligado nesta instalação: nenhuma data comemorativa sai até ele ser ligado.', trim($aviso->text()));
    }

    #[TestDox('O script vem com os da seção Comercial; no modo venda não há o bloco nem o script')]
    public function testScriptEModoVenda(): void
    {
        self::assertCount(1, $this->doc->filter('script[src*="js/master/comercial-datas.js"]'));
        $venda = new Crawler('<!doctype html><html><body>' . static::getContainer()->get(Environment::class)->render('master/_comercial.html.twig', ['modo' => 'venda']) . '</body></html>');
        self::assertCount(0, $venda->filter('[data-mc-datas]'));
        self::assertCount(0, $venda->filter('script[src*="js/master/comercial-datas.js"]'));
    }
}
