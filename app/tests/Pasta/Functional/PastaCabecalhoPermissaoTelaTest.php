<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Twig\PermissionExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PAS-014 — o cabeçalho da pasta só oferece o controle de escrita que o servidor deixaria usar:
 * Editar dados, Vincular processo, Trocar responsável, Arquivar, a troca de prioridade e a de
 * situação pedem EDIT na pasta; Excluir e Restaurar pedem DELETE (a mesma decisão do
 * `denyResourceAccessUnlessGranted` de cada rota, via `can_access_resource()`).
 *
 * O servidor continua recusando — estes testes provam só a tela.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(PermissionExtension::class)]
#[Group('pasta')]
final class PastaCabecalhoPermissaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function menu(Crawler $crawler): Crawler
    {
        return $crawler->filter('.ps-cabecalho > .ps-cab-linha1 > .ps-cab-acoes > .ps-pop-wrap > #psMenuAcoes');
    }

    #[TestDox('com EDIT e DELETE (admin): o ⋮ tem Editar, Vincular, Trocar responsável, Arquivar e Excluir; prioridade e situação abrem menu')]
    public function testComTodasAsPermissoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $menu    = $this->menu($crawler);

        self::assertCount(1, $menu);
        self::assertCount(1, $menu->filter('#psMenuAcoes > button[data-bs-target="#modalEditarPasta"]'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > button[data-vp-abrir][aria-controls="modalVincularProcesso"]'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > #psMenuTrocarResponsavel'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > .js-situacao-alternar'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > form.js-excluir-pasta'));
        self::assertCount(1, $crawler->filter('.ps-cab-etiquetas > .ps-pop-wrap > button.pasta-prioridade-btn[data-ps-pop="psPrioridade"]'));
        self::assertCount(1, $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap > button#btn-alternar-status'));
        self::assertCount(1, $crawler->filter('[data-trilho="processo"] > [data-campo="processo"] .ps-link-btn--vincular'));
    }

    #[TestDox('só VIEW: nenhum controle de escrita no cabeçalho; ficam Histórico, Timeline, Copiar link, Imprimir e Favoritos')]
    public function testSoLeitura(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta  = $this->criarPasta($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $menu    = $this->menu($crawler);

        self::assertCount(1, $menu, 'o ⋮ continua: tem leitura e preferência');
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalEditarPasta"]'), 'sem Editar dados');
        self::assertCount(0, $menu->filter('[data-ps-atalho="e"]'), 'sem o atalho E');
        self::assertCount(0, $crawler->filter('.js-excluir-pasta'), 'sem Excluir pasta');
        self::assertCount(0, $menu->filter('[data-vp-abrir][aria-controls="modalVincularProcesso"]'));
        self::assertCount(0, $crawler->filter('#psMenuTrocarResponsavel'));
        self::assertCount(0, $crawler->filter('.js-situacao-alternar'));
        self::assertCount(0, $crawler->filter('[data-trilho="processo"] .ps-link-btn--vincular'), 'sem o "vincular" da faixa');

        self::assertCount(1, $menu->filter('#psMenuAcoes > #psHistoricoAbrir'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > #psTimelineAbrir'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > .js-copiar-link'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > a[href$="/imprimir"], #psMenuAcoes > a[target="_blank"]'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > .js-pasta-favorito'));

        // O menu não termina num separador órfão.
        $filhos = $menu->children()->each(static fn (Crawler $n) => (string) $n->attr('class'));
        self::assertStringNotContainsString('ps-pop-sep', (string) end($filhos));

        // Prioridade e situação viram selos, sem menu.
        self::assertCount(0, $crawler->filter('.pasta-prioridade-btn, #psPrioridade'));
        self::assertCount(1, $crawler->filter('.ps-cab-etiquetas > span.ps-prio.ps-prio--normal'));
        self::assertCount(0, $crawler->filter('#btn-alternar-status, #psSituacao'));
        self::assertCount(1, $crawler->filter('.ps-abas-faixa > .ps-situacao-wrap > span.ps-situacao-pill'));
    }

    #[TestDox('EDIT sem DELETE: Editar dados e Arquivar aparecem; Excluir não')]
    public function testEditorSemExcluir(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta  = $this->criarPasta($tenant);
        $editor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit', 'modules.pastas.view']);

        $this->logarComTenant($client, $editor, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $menu    = $this->menu($crawler);

        self::assertCount(1, $menu->filter('#psMenuAcoes > button[data-bs-target="#modalEditarPasta"]'));
        self::assertCount(1, $menu->filter('#psMenuAcoes > .js-situacao-alternar'));
        self::assertCount(0, $crawler->filter('.js-excluir-pasta'));
        self::assertCount(1, $crawler->filter('button#btn-alternar-status'));
    }

    #[TestDox('DELETE sem EDIT: Excluir aparece, Editar dados não')]
    public function testExcluirSemEditar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta  = $this->criarPasta($tenant);
        $quem   = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.delete', 'modules.pastas.view']);

        $this->logarComTenant($client, $quem, $tenant);
        $crawler = $this->abrir($client, $pasta);
        $menu    = $this->menu($crawler);

        self::assertCount(1, $menu->filter('#psMenuAcoes > form.js-excluir-pasta'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalEditarPasta"]'));
    }

    #[TestDox('lápide: Restaurar só para quem tem DELETE (a rota pasta_restaurar pede DELETE)')]
    public function testRestaurarNaLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.pastas.view']);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        self::assertCount(1, $crawler->filter('.ps-cab-acoes > form[action$="/restaurar"]'));

        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $this->abrir($client, $pasta);
        self::assertCount(0, $crawler->filter('form[action$="/restaurar"]'));
        self::assertCount(1, $crawler->filter('.ps-cab-acoes > #psHistoricoAbrir'), 'o histórico continua');
    }
}
