<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Timeline inteligente na tela da pasta (L18): o item no menu ⋮ (com o atalho T do desenho) e o
 * esqueleto do painel. Arranjo com combinador de FILHO DIRETO; o desenho (gradiente, medidas,
 * cores) fica para o smoke do dono.
 *
 * Rótulo honesto (D-IA): nada de ✦ nem "IA" no painel.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaTimelineArranjoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('Menu ⋮: "Timeline inteligente" logo depois do Histórico, com o atalho T e apontando para o painel')]
    public function testItemNoMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $item = $crawler->filter('#psMenuAcoes > button#psTimelineAbrir.ps-pop-item');
        self::assertCount(1, $item);
        self::assertSame('t', $item->attr('data-ps-atalho'));
        self::assertSame('psTimeline', $item->attr('aria-controls'));
        self::assertSame('T', trim($item->filter('#psTimelineAbrir > kbd.ps-pop-atalho')->text()));
        self::assertSame('Timeline inteligente', trim($item->filter('#psTimelineAbrir > span')->text()));

        // Ordem do desenho: Editar dados (E), Histórico (H), Timeline inteligente (T).
        self::assertSame('psTimelineAbrir', $crawler->filter('#psMenuAcoes > #psHistoricoAbrir + #psTimelineAbrir')->attr('id'));
    }

    #[TestDox('Painel: cabeçalho, barra (busca, chips, período, pessoa, resumir), lista e rodapé "por regras"')]
    public function testEsqueletoDoPainel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $painel = $crawler->filter('aside#psTimeline.ps-tl[role="dialog"][aria-hidden="true"]');
        self::assertCount(1, $painel);
        self::assertSame('/pasta/' . $pasta->getId() . '/timeline', $painel->attr('data-url'));
        self::assertNull($painel->attr('data-chave'), 'Sem chave de localStorage: "Enquanto você estava fora" espera persistência no servidor.');

        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-cab > .ps-tl-cab-texto > h2#psTimelineTitulo'));
        self::assertSame('Timeline inteligente', trim($crawler->filter('#psTimelineTitulo')->text()));
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $crawler->filter('#psTimeline > .ps-tl-cab > .ps-tl-cab-texto > .ps-tl-cab-sub')->text());
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-cab > button.ps-tl-fechar'));

        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > input.ps-tl-busca[data-tl-busca]'));
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > .ps-tl-chips[data-tl-chips]'));
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > .ps-tl-linha > select[data-tl-periodo]'));
        self::assertSame(
            ['tudo', 'hoje', 'ontem', 'semana', 'mes'],
            $crawler->filter('select[data-tl-periodo] > option')->each(static fn ($o) => $o->attr('value')),
        );
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > .ps-tl-linha > select[data-tl-pessoa]'));
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > .ps-tl-linha > button[data-tl-resumir]'));
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-lista[data-tl-lista]'));
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-rodape > .ps-tl-rotulo'));
        self::assertStringContainsString('por regras', $crawler->filter('#psTimeline > .ps-tl-rodape > .ps-tl-rotulo')->text());

        // Fora de .ps-page, como o drawer do histórico (é fixo e cobre a tela).
        self::assertCount(0, $crawler->filter('.ps-page #psTimeline'));
        self::assertCount(1, $crawler->filter('#psTimelineOverlay.ps-tl-overlay'));
        self::assertCount(1, $crawler->filter('script[src="/js/pasta-timeline.js"]'));

        // D-IA: nenhum ✦ e nenhuma promessa de IA no painel.
        $texto = $crawler->filter('#psTimeline')->text();
        self::assertStringNotContainsString('✦', $texto);
        self::assertDoesNotMatchRegularExpression('/\bIA\b/u', $texto);
    }

    #[TestDox('Regra do dono: a timeline não guarda nada no navegador — sem localStorage/sessionStorage e sem "Enquanto você estava fora"')]
    public function testJsSemArmazenamentoNoNavegador(): void
    {
        $js = file_get_contents(dirname(__DIR__, 3) . '/public/js/pasta-timeline.js');
        self::assertIsString($js);

        // Navegador não substitui persistência: o "Enquanto você estava fora" volta só quando a
        // última abertura for guardada por usuário no servidor.
        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
        self::assertStringNotContainsString('ps-tl-fora', $js);
        self::assertStringNotContainsString("'desde'", $js);
    }
}
