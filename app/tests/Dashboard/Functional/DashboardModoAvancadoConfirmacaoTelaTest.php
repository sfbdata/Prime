<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DASH-026 — confirmação "Ativar Modo Avançado?" e cartão "Conhecer o Modo avançado".
 *
 * A confirmação só abre na PRIMEIRA ativação (README do desenho, "Ativação"): o servidor diz se o
 * usuário já gravou a chave (`data-ia-avancado-escolhido` no `.db-page`). O cartão volta a cada
 * abertura do painel com o modo desligado (README, "Modo básico x Modo avançado") — por isso não
 * precisa de preferência: o tempo de 6s é do JS (DashboardModoAvancadoJsTest) e o fade do CSS.
 *
 * Teste de PHPUnit lê HTML, não tempo nem animação: o que dá para travar é o texto, o arranjo
 * (filho direto) e o estado inicial escondido; que o cartão some aos 6s é smoke do dono.
 */
#[CoversClass(DashboardController::class)]
final class DashboardModoAvancadoConfirmacaoTelaTest extends DashboardWebTestCase
{
    private const PAINEL = '.db-page [data-filtro-root] > [data-filtro-resultado] > .db-ia > aside.db-ia-painel';

    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    private function texto(Crawler $no): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($no->text()));
    }

    #[TestDox('Sem escolha gravada: o .db-page diz "nao" — ligar vai pedir a confirmação')]
    public function testSemEscolhaPedeConfirmacao(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('nao', $crawler->filter('section.db-page')->attr('data-ia-avancado-escolhido'));
    }

    #[TestDox('Com escolha gravada (inclusive DESLIGADO) o .db-page diz "sim": liga direto, sem confirmação')]
    public function testComEscolhaNaoPedeDeNovo(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.ia_avancado', 'desligado');

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame('sim', $crawler->filter('section.db-page')->attr('data-ia-avancado-escolhido'));
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'), 'desligado gravado: sem classe');
    }

    #[TestDox('A escolha de um colega não dispensa a MINHA confirmação')]
    public function testEscolhaDoColegaNaoConta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Avançado');
        $this->repo()->gravar($tenant, $colega, 'dashboard.ia_avancado', 'ligado');

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame('nao', $crawler->filter('section.db-page')->attr('data-ia-avancado-escolhido'));
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
    }

    #[TestDox('Confirmação: alertdialog escondido sobre o painel, com o texto do desenho e os dois botões')]
    public function testConfirmacaoComOTextoDoDesenho(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        $veu = $crawler->filter(self::PAINEL . ' > .db-ia-sub + div.db-ia-confirma[data-ia-confirma]');
        self::assertCount(1, $veu, 'véu filho direto do painel, logo depois do cabeçalho (dc L939)');
        self::assertNotNull($veu->attr('hidden'), 'nasce escondida: quem abre é o JS no clique');

        $caixa = $veu->filter('div.db-ia-confirma > .db-ia-confirma-caixa[role="alertdialog"]');
        self::assertCount(1, $caixa);
        self::assertSame('Ativar Modo Avançado', $caixa->attr('aria-label'));
        self::assertSame('true', $caixa->attr('aria-modal'));
        self::assertSame('Ativar Modo Avançado?', $this->texto($caixa->filter('.db-ia-confirma-titulo')));
        self::assertCount(1, $caixa->filter('.db-ia-confirma-cab > .db-ia-confirma-icone > i.bi-cpu'));
        self::assertSame(
            'A BlueJus Intelligence passará a fazer análises ampliadas do escritório, usando apenas os dados disponíveis ao seu perfil de acesso.',
            $this->texto($caixa->filter('#dbIaConfirmaTexto')),
        );
        self::assertSame('Poderá analisar', $this->texto($caixa->filter('.db-ia-confirma-rotulo')));

        $acoes = $caixa->filter('.db-ia-confirma-acoes > button');
        self::assertSame(['Agora não', 'Ativar Modo Avançado'], $acoes->each(fn (Crawler $b): string => $this->texto($b)));
        self::assertNotNull($acoes->eq(0)->attr('data-ia-confirma-cancelar'));
        self::assertNotNull($acoes->eq(1)->attr('data-ia-confirma-ativar'));
    }

    #[TestDox('"Poderá analisar" lista só o que o painel por regras lê de verdade (sem Processos, Produtividade, Tendências, Histórico)')]
    public function testEscopoHonesto(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        $itens = $crawler->filter(self::PAINEL . ' .db-ia-confirma-escopo > li');
        self::assertSame(
            ['Tarefas', 'Prazos', 'Equipes', 'Gargalos', 'Indicadores', 'Riscos operacionais'],
            $itens->each(fn (Crawler $li): string => $this->texto($li)),
        );
        self::assertCount(6, $itens->filter('li > i.bi-check2'));
    }

    #[TestDox('Cartão "Conhecer o Modo avançado": no rodapé, logo antes da faixa "Acompanhar…", escondido até o JS abrir')]
    public function testCartaoNoRodape(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        $cartao = $crawler->filter(self::PAINEL . ' > .db-ia-corpo + button.db-ia-teaser[data-ia-teaser] + .db-ia-rodape');
        self::assertCount(1, $cartao, 'entre o corpo rolável e o rodapé (dc L1412-1419)');
        $cartao = $crawler->filter(self::PAINEL . ' > button.db-ia-teaser');
        self::assertNotNull($cartao->attr('hidden'));
        self::assertSame('button', $cartao->attr('type'));
        self::assertSame('Conhecer o Modo avançado', $this->texto($cartao->filter('.db-ia-teaser-titulo')));
        self::assertSame('Visão estratégica, riscos e Central de Inteligência.', $this->texto($cartao->filter('.db-ia-teaser-sub')));
        self::assertCount(1, $cartao->filter('button > .db-ia-teaser-icone > i.bi-cpu'));
        self::assertCount(1, $cartao->filter('button > i.bi-arrow-right'));
    }

    #[TestDox('O fragmento do XHR traz confirmação e cartão (o painel é trocado a cada filtro)')]
    public function testXhrTrazConfirmacaoECartao(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('aside.db-ia-painel > div.db-ia-confirma[hidden]'));
        self::assertCount(1, $crawler->filter('aside.db-ia-painel > button.db-ia-teaser[hidden]'));
    }

    #[TestDox('CSS: cartão some com o modo ligado e tem a saída com fade/recolhimento de 0,5s; a confirmação tem até 380px')]
    public function testCss(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/dashboard-inteligencia.css');

        self::assertStringContainsString('.db-page.db-page--ia-avancado .db-ia-teaser { display: none; }', $css);
        self::assertStringContainsString('.db-page .db-ia-teaser.is-saindo { max-height: 0; opacity: 0;', $css);
        self::assertStringContainsString('transition: opacity .5s ease, transform .5s var(--db-ia-ease), max-height .5s ease', $css);
        self::assertStringContainsString('animation: dbIaBloco .45s var(--db-ia-ease) .5s backwards;', $css);
        self::assertStringContainsString('max-width: 380px;', $css);
        self::assertStringContainsString('.db-page .db-ia-confirma[hidden] { display: none; }', $css);
    }
}
