<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Twig\PermissionExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PMC-004 — o ⋮ do cartão do processo (aba Processo da pasta) ganha "Editar dados do processo"
 * (desenho, procMenu dc L.2922: ícone `bi-pencil`, depois de "Ver todas as informações" e antes
 * de "Ver movimentações"). Leva a `processo_edit` e só aparece para quem tem EDIT no PROCESSO
 * (a guarda da própria rota) e fora da lápide.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(PermissionExtension::class)]
#[Group('pasta')]
final class PastaProcessoEditarItemTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private const NUMERO = '07011345720258070007';

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function menu(Crawler $crawler, Processo $processo): Crawler
    {
        $menu = $crawler->filter('.ps-processos > .ps-registro > article.ps-processo[data-processo-id="' . $processo->getId() . '"] > #psProcMenu' . $processo->getId());
        self::assertCount(1, $menu, 'o ⋮ do cartão existe');

        return $menu;
    }

    /** @return array{Pasta, Processo} */
    private function pastaComProcesso(\App\Entity\Tenant\Tenant $tenant): array
    {
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        return [$pasta, $processo];
    }

    #[TestDox('com EDIT no processo: item com bi-pencil que abre a edição NO CARTÃO (P5b), entre "Ver todas as informações" e "Ver movimentações"')]
    public function testItemParaQuemPodeEditar(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $menu = $this->menu($this->abrir($client, $pasta), $processo);

        $item = $menu->filter('#psProcMenu' . $processo->getId() . ' > button.ps-processo-menu-item.js-proc-editar');
        self::assertCount(1, $item);
        self::assertSame('#psProcEdicao' . $processo->getId(), $item->attr('data-edicao'));
        self::assertSame('Editar dados do processo', trim($item->text()));
        self::assertCount(1, $item->filter('i.bi.bi-pencil'));

        $classes = $menu->children()->each(static fn (Crawler $n) => (string) $n->attr('class'));
        $editar  = array_search('ps-processo-menu-item js-proc-editar', $classes, true);
        $push    = array_search('ps-processo-menu-item js-proc-ver-movimentacoes', $classes, true);
        $todas   = array_search('ps-processo-menu-item js-proc-alternar-menu', $classes, true);
        self::assertIsInt($editar);
        self::assertSame($push - 1, $editar, 'logo antes de "Ver movimentações"');
        self::assertSame($todas + 1, $editar, 'logo depois de "Ver todas as informações"');
    }

    #[TestDox('sem EDIT no processo (só ver a pasta e o processo): o ⋮ continua, sem o item')]
    public function testSemEditarNoProcesso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit', 'resources.processo.view']);

        $this->logarComTenant($client, $leitor, $tenant);
        $menu = $this->menu($this->abrir($client, $pasta), $processo);

        self::assertCount(0, $menu->filter('.js-proc-editar'));
        self::assertCount(1, $menu->filter('.js-proc-ver-movimentacoes'), 'o resto do ⋮ fica');
    }

    #[TestDox('EDIT no processo sem EDIT na pasta: o item aparece (a guarda é a do processo)')]
    public function testEditarProcessoSemEditarPasta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $quem = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.edit']);

        $this->logarComTenant($client, $quem, $tenant);
        $menu = $this->menu($this->abrir($client, $pasta), $processo);

        self::assertCount(1, $menu->filter('button.js-proc-editar'));
    }

    #[TestDox('lápide: sem o item, nem para quem pode editar')]
    public function testLapide(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta, $processo] = $this->pastaComProcesso($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(0, $crawler->filter('.js-proc-editar'));
    }
}
