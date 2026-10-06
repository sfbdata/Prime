<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * O botão "BlueJus Intelligence" no cabeçalho da pasta e o drawer dos agentes (spec
 * inteligencia-agentes-da-pasta §5). O botão nunca finge: carrega o motivo REAL da
 * `Disponibilidade`; dentro do drawer cada agente mostra "Gerar análise" habilitado ou o motivo,
 * desabilitado. Sem `modules.inteligencia.view`, nada de IA no cabeçalho.
 *
 * Arranjo com combinador de FILHO DIRETO (`.ps-cabecalho > .ps-cab-cliente > .ps-cab-ia`): "existe
 * na página" seria verdade com o botão no lugar errado. Estilo e JS não são visíveis aqui.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaCabecalhoIaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const BOTAO = '.ps-page > .ps-cabecalho > .ps-cab-cliente > button.ps-cab-ia';
    private const DRAWER = 'aside.ia-drawer#psIaDrawer[data-ia-drawer]';

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function abrirPasta(KernelBrowser $client, int $pastaId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('Sem provedor: botão à direita da linha do cliente, com o motivo real; o drawer existe (oculto) com os 7 agentes travados')]
    public function testSemProvedor(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->provedorFalso()->desconfigurar();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame(1, $botao->count(), 'o botão mora na linha do cliente do cabeçalho (filho direto)');
        self::assertSame('0', $botao->attr('data-ia-disponivel'));
        self::assertSame('nao_configurada_na_plataforma', $botao->attr('data-ia-motivo'));
        self::assertSame('IA não configurada nesta instalação', $botao->attr('title'));
        self::assertSame('psIaDrawer', $botao->attr('aria-controls'));
        self::assertSame('BLUEJUS', trim($botao->filter('.ps-cab-ia-marca')->text()));
        self::assertSame('Intelligence', trim($botao->filter('.ps-cab-ia-nome')->text()));

        $drawer = $crawler->filter(self::DRAWER);
        self::assertSame(1, $drawer->count());
        self::assertNotNull($drawer->attr('hidden'), 'nasce fechado');
        self::assertSame("/pasta/{$pasta->getId()}/ia/agentes", $drawer->attr('data-ia-painel-url'));
        self::assertNotEmpty($drawer->attr('data-ia-token'));
        self::assertStringContainsString('IA não configurada nesta instalação', $drawer->filter('.ia-drawer-cab > .ia-drawer-motivo')->text());
        self::assertSame(7, $drawer->filter('.ia-drawer-corpo > .ia-agentes > .ia-agente')->count());
        self::assertSame(
            ['gestor', 'processual', 'documental', 'prazos', 'relatorios', 'cliente', 'juridico'],
            $drawer->filter('.ia-agente')->each(static fn (Crawler $s): ?string => $s->attr('data-ia-agente')),
        );
        $ctas = $drawer->filter('.ia-agente > button.ia-cta[data-ia-gerar]');
        self::assertSame(7, $ctas->count());
        $ctas->each(static function (Crawler $cta): void {
            self::assertNotNull($cta->attr('disabled'), 'sem provedor nenhum agente é clicável');
            self::assertSame('0', $cta->attr('data-ia-disponivel'));
            self::assertSame('nao_configurada_na_plataforma', $cta->attr('data-ia-motivo'));
            self::assertStringContainsString('IA não configurada nesta instalação', $cta->text());
            self::assertNull($cta->attr('data-ia-url'), 'desabilitado não carrega a rota do pedido');
        });
        self::assertSame(1, $crawler->filter('link[href*="css/pasta-ia-agentes.css"]')->count());
        self::assertSame(1, $crawler->filter('script[src*="js/pasta-ia-agentes.js"]')->count());
    }

    #[TestDox('Disponível: botão habilitado e cada agente com "Gerar análise", papel e o que lê')]
    public function testDisponivel(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        $botao = $crawler->filter(self::BOTAO);
        self::assertSame('1', $botao->attr('data-ia-disponivel'));
        self::assertSame('disponivel', $botao->attr('data-ia-motivo'));
        self::assertStringContainsString('BlueJus IA', (string) $botao->attr('title'));

        $drawer = $crawler->filter(self::DRAWER);
        self::assertSame(0, $drawer->filter('.ia-drawer-motivo')->count(), 'disponível não tem faixa de motivo');
        $gestor = $drawer->filter('.ia-agente[data-ia-agente="gestor"]');
        self::assertSame('Agente Gestor', trim($gestor->filter('.ia-agente-cab > .ia-agente-txt > .ia-agente-nome')->text()));
        self::assertStringContainsString('visão executiva', $gestor->filter('.ia-agente-papel')->text());
        self::assertStringContainsString('Lê: dados da pasta, processos, clientes', $gestor->filter('.ia-agente-le')->text());
        self::assertStringContainsString('financeiro', $gestor->filter('.ia-agente-le')->text());
        $cta = $gestor->filter('button.ia-cta[data-ia-gerar]');
        self::assertNull($cta->attr('disabled'));
        self::assertSame('1', $cta->attr('data-ia-disponivel'));
        self::assertSame("/pasta/{$pasta->getId()}/ia/agentes/gestor/analises", $cta->attr('data-ia-url'));
        self::assertStringContainsString('Gerar análise', $cta->text());
        // O painel ainda não foi carregado (é o JS que busca ao abrir): a lista diz "carregando".
        self::assertSame('0', $drawer->filter('.ia-agentes')->attr('data-ia-carregado'));
        self::assertSame(1, $gestor->filter('.ia-ag-lista[data-ia-lista="gestor"] > [data-ia-carregando]')->count());
        self::assertStringNotContainsString('financeiro', $drawer->filter('.ia-agente[data-ia-agente="processual"] .ia-agente-le')->text());

        // Chips do contexto desta tela.
        $chips = $drawer->filter('.ia-drawer-cab > .ia-drawer-chips > .ia-chip');
        self::assertSame(4, $chips->count());
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $chips->eq(0)->text());
        self::assertStringContainsString('1 processo', $chips->eq(1)->text());
    }

    #[TestDox('Escritório com a IA desligada: botão e agentes com "desligada neste escritório"')]
    public function testTenantDesligado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame('desligada_no_escritorio', $crawler->filter(self::BOTAO)->attr('data-ia-motivo'));
        self::assertStringContainsString('desligada neste escritório', (string) $crawler->filter(self::BOTAO)->attr('title'));
        self::assertStringContainsString('desligada neste escritório', $crawler->filter(self::DRAWER . ' .ia-agente[data-ia-agente="cliente"] .ia-cta')->text());
    }

    #[TestDox('Sem modules.inteligencia.view: nem botão, nem drawer, nem script no cabeçalho')]
    public function testSemPermissaoNadaDeIa(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $admin);
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrirPasta($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('.ps-cabecalho > .ps-cab-cliente')->count(), 'o cabeçalho continua lá');
        self::assertSame(0, $crawler->filter('.ps-cab-ia')->count());
        self::assertSame(0, $crawler->filter('.ia-drawer')->count());
        self::assertSame(0, $crawler->filter('script[src*="pasta-ia-agentes.js"]')->count());
        self::assertStringNotContainsString('Agente Gestor', (string) $client->getResponse()->getContent());
    }
}
