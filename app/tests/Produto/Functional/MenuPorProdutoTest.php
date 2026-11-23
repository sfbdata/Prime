<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Twig\InteligenciaExtension;
use App\Produto\Enum\Produto;
use App\Produto\Service\ProdutosNaTela;
use App\Produto\Twig\ProdutoExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * O menu por produto (spec docs/specs/multiproduto-contratacao-e-voto.md, §7 e §12, "PR 7, menu"): com a chave ligada,
 * o menu lateral, a barra secundária e o logo seguem os produtos do escritório da sessão; desligada, tudo como hoje.
 * Os links são conferidos como filhos diretos da lista do menu (`ul.pje-menu-list > li > a`).
 */
#[CoversClass(ProdutoExtension::class)]
#[CoversClass(ProdutosNaTela::class)]
#[CoversClass(InteligenciaExtension::class)]
final class MenuPorProdutoTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;

    private const LINKS_DO_EXPEDIENTE = ['expediente_index', 'demandas_index'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('desligado: escritório só Voto vê o menu de hoje (Expediente e Demandas) ')]
    public function testDesligadoMenuDeHoje(): void
    {
        $this->modo('desligado');
        $menu = $this->menuDe($this->escritorio(Produto::Voto));

        foreach (self::LINKS_DO_EXPEDIENTE as $href) {
            self::assertCount(1, $this->linkDoMenu($menu, $href), $href);
        }
    }

    #[TestDox('bloqueio: escritório só Voto fica sem os links do Expediente e da I.A., sem a barra do Expediente, e o logo leva ao Voto')]
    public function testSoVotoSemExpediente(): void
    {
        $this->modo('bloqueio');
        $menu = $this->menuDe($this->escritorio(Produto::Voto));

        foreach ([...self::LINKS_DO_EXPEDIENTE, 'dashboard_index', 'agenda_index', 'chat_ia_pagina'] as $href) {
            self::assertCount(0, $this->linkDoMenu($menu, $href), $href);
        }
        self::assertCount(0, $menu->filter('.navtop-subnav > a.navtop-subnav-link'), 'barra secundária vazia');
        self::assertCount(1, $menu->filter('.navtop-subnav'), 'a barra fica: a altura do cabeçalho conta com ela');
        self::assertSame($this->caminho('voto_index'), $menu->filter('a.navtop-brand')->attr('href'));
    }

    #[TestDox('observacao: o menu já segue os produtos (a mesma chave governa a guarda e o menu, D3)')]
    public function testObservacaoTambemEscondeOMenu(): void
    {
        $this->modo('observacao');
        $menu = $this->menuDe($this->escritorio(Produto::Voto));

        self::assertCount(0, $this->linkDoMenu($menu, 'expediente_index'));
    }

    #[TestDox('bloqueio: escritório completo (sem linha) vê os mesmos links de hoje, filhos diretos da lista do menu')]
    public function testCompletoMenuDeHoje(): void
    {
        $this->modo('bloqueio');
        $menu = $this->menuDe($this->escritorio());

        foreach (self::LINKS_DO_EXPEDIENTE as $href) {
            self::assertCount(1, $this->linkDoMenu($menu, $href), $href);
        }
        self::assertSame($this->caminho('expediente_index'), $menu->filter('a.navtop-brand')->attr('href'));
        self::assertGreaterThan(0, $menu->filter('.navtop-subnav > a.navtop-subnav-link')->count());
    }

    #[TestDox('bloqueio: escritório sem I.A. fica sem o Chat I.A. e com ia_disponibilidade() "desligada no escritório"')]
    public function testSemIa(): void
    {
        $this->modo('bloqueio');
        $menu = $this->menuDe($this->escritorio(Produto::Expediente));

        self::assertCount(1, $this->linkDoMenu($menu, 'expediente_index'));
        self::assertCount(0, $this->linkDoMenu($menu, 'chat_ia_pagina'));
        self::assertSame(Disponibilidade::DesligadaNoEscritorio, $this->iaDisponibilidadeNaRequisicao());
    }

    private function menuDe(Tenant $tenant): Crawler
    {
        $this->logarComTenant($this->client, $this->primario($tenant), $tenant);
        $crawler = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function linkDoMenu(Crawler $menu, string $rota): Crawler
    {
        return $menu->filter(sprintf('ul.pje-menu-list > li.pje-menu-item > a.pje-menu-link[href="%s"]', $this->caminho($rota)));
    }

    private function caminho(string $rota): string
    {
        return static::getContainer()->get('router')->generate($rota);
    }

    /** A função Twig lê o escritório da sessão: chama de dentro de uma requisição de verdade (a página do perfil). */
    private function iaDisponibilidadeNaRequisicao(): Disponibilidade
    {
        $requisicao = $this->client->getRequest();
        $pilha = static::getContainer()->get('request_stack');
        $pilha->push($requisicao);
        try {
            return static::getContainer()->get(InteligenciaExtension::class)->iaDisponibilidade();
        } finally {
            $pilha->pop();
        }
    }
}
