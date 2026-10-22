<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Yaml\Yaml;

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
        self::assertNull($painel->attr('data-chave'), 'Sem chave de localStorage: "Enquanto você estava fora" vem do servidor.');
        self::assertSame('/pasta/' . $pasta->getId() . '/timeline/visita', $painel->attr('data-visita-url'));
        self::assertNotSame('', (string) $painel->attr('data-visita-token'));

        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-cab > .ps-tl-cab-texto > h2#psTimelineTitulo'));
        self::assertSame('Timeline inteligente', trim($crawler->filter('#psTimelineTitulo')->text()));
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $crawler->filter('#psTimeline > .ps-tl-cab > .ps-tl-cab-texto > .ps-tl-cab-sub')->text());
        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-cab > button.ps-tl-fechar'));

        self::assertCount(1, $crawler->filter('#psTimeline > .ps-tl-barra > input.ps-tl-busca[data-tl-busca]'));
        // C6-PAS-08: o texto exato do desenho (central:3408), com "telefone" — não "prazo".
        self::assertSame(
            'Buscar: "quando falaram sobre o contrato", "Jéssica", "telefone"…',
            $crawler->filter('#psTimeline > .ps-tl-barra > input.ps-tl-busca')->attr('placeholder'),
        );
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

    #[TestDox('Regra do dono: a timeline não guarda nada no navegador — o "Enquanto você estava fora" vem da visita gravada no servidor')]
    public function testJsSemArmazenamentoNoNavegador(): void
    {
        $js = file_get_contents(dirname(__DIR__, 3) . '/public/js/pasta-timeline.js');
        self::assertIsString($js);

        // Navegador não substitui persistência: o "Enquanto você estava fora" (PAS-043) sai da
        // visita gravada por usuário no servidor — o JS só a registra (POST) e desenha o que vem.
        self::assertStringNotContainsString('localStorage', $js);
        self::assertStringNotContainsString('sessionStorage', $js);
        self::assertStringNotContainsString("'desde'", $js);
        self::assertStringContainsString("getAttribute('data-visita-url')", $js);
        self::assertStringContainsString('registrarVisita().then(carregar)', $js);
        self::assertStringContainsString('Enquanto você estava fora: ', $js);
        // D-IA: o 🧠 do desenho fica fora, como o ✦.
        self::assertStringNotContainsString('🧠', $js);
    }

    #[TestDox('"Ver alteração" (PAS-045): o cartão só oferece o botão com `antes`, alterna para "Ocultar alteração" e mostra Antes/Depois por textContent')]
    public function testJsVerAlteracao(): void
    {
        $js = file_get_contents(dirname(__DIR__, 3) . '/public/js/pasta-timeline.js');
        self::assertIsString($js);

        self::assertStringContainsString("if (e.antes) {", $js);
        self::assertStringContainsString("alteracao ? BJ_T.t('pasta_resp.js.timeline.ocultar_alteracao') : BJ_T.t('pasta_resp.js.timeline.ver_alteracao')", $js);
        self::assertStringContainsString("[[BJ_T.t('pasta_resp.js.timeline.antes'), String(e.antes)], [BJ_T.t('pasta_resp.js.timeline.depois'), depois]]", $js);
        self::assertStringContainsString("BJ_T.t('pasta_resp.js.timeline.alterado_em', { quando: new Date(e.alteradoEm).toLocaleString(BJFormato.idioma()) })", $js);
        $cat = Yaml::parseFile(dirname(__DIR__, 3) . '/translations/messages+intl-icu.pt_BR.yaml')['pasta_resp']['js']['timeline'];
        self::assertSame(['Ocultar alteração', 'Ver alteração', 'Antes:', 'Depois:', 'Alterado em {quando}'], [$cat['ocultar_alteracao'], $cat['ver_alteracao'], $cat['antes'], $cat['depois'], $cat['alterado_em']]);
        // Texto de usuário nunca vira HTML.
        self::assertStringNotContainsString('.innerHTML', $js);
    }
}
