<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Entradas do relatório geral de processos na aba Processo (desenho 1.2.3: `procHdMenu` L6588 e
 * `procMenu` L2930; spec relatorio-processos-controladoria §3-4): no ⋮ do cabeçalho, "Relatório
 * geral de processos", "Enviar à Controladoria agora", separador e o item do administrativo; no ⋮
 * do cartão, o relatório antes de "Imprimir resumo". A prévia (`#rpcImpressao`) mora na tela da pasta.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class RelatorioDeProcessosMenuTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<string> */
    private static function textos(Crawler $itens): array
    {
        return $itens->each(static fn (Crawler $n): string => trim($n->text()));
    }

    #[TestDox('⋮ do cabeçalho: Relatório, Enviar à Controladoria, separador e Administrativo, na ordem do desenho')]
    public function testMenuDoCabecalho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pasta);
        $menu    = '#processo .ps-processos > #psProcHdMenu' . $pasta->getId();

        self::assertSame(
            ['Relatório geral de processos', 'Enviar à Controladoria agora', 'Marcar como administrativo sem processo'],
            self::textos($crawler->filter($menu . ' .ps-processo-menu-item')),
        );
        $relatorio = $crawler->filter($menu . ' > a.ps-processo-menu-item.js-relatorio-processos');
        self::assertSame('/expediente/relatorio-processos', $relatorio->attr('href'));
        self::assertCount(1, $relatorio->filter('i.bi-file-earmark-bar-graph'));

        $form = $crawler->filter($menu . ' > a.js-relatorio-processos + form.js-relatorio-enviar + .ps-processo-menu-sep + button.js-proc-hd-adm');
        self::assertCount(1, $form, 'Relatório, Enviar, separador e Administrativo como filhos diretos, nessa ordem');
        $enviar = $crawler->filter($menu . ' > form.js-relatorio-enviar');
        self::assertSame('/expediente/relatorio-processos/enviar', $enviar->attr('action'));
        self::assertSame('post', strtolower((string) $enviar->attr('method')));
        self::assertSame((string) $pasta->getId(), $enviar->filter('input[name="pasta"]')->attr('value'));
        self::assertNotSame('', (string) $enviar->filter('input[name="_token"]')->attr('value'));
        self::assertCount(1, $enviar->filter('button[type="submit"].ps-processo-menu-item > i.bi-send'));
    }

    #[TestDox('A prévia do relatório está na tela, apontando para a folha e para o PDF')]
    public function testPreviaNaTela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $previa = $this->abrir($client, $pasta)->filter('#rpcImpressao.psi-fundo[hidden]');

        self::assertCount(1, $previa);
        self::assertSame('/expediente/relatorio-processos', $previa->attr('data-url-folha'));
        self::assertSame('/expediente/relatorio-processos/pdf', $previa->attr('data-url-pdf'));
        self::assertSame(['Cabeçalho do escritório'], self::textos($previa->filter('.js-rpc-opcao')));
        self::assertSame('10,5 pt', trim($previa->filter('#rpcImpressaoFonte')->text()));
    }

    #[TestDox('⋮ do cartão: "Relatório geral de processos" logo antes de "Imprimir resumo"')]
    public function testMenuDoCartao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '07011345720258070007');
        $this->vincular($pasta, $processo);
        $this->logarComTenant($client, $user, $tenant);

        $menu = $this->abrir($client, $pasta)->filter('#psProcMenu' . $processo->getId());

        self::assertCount(1, $menu->filter('#psProcMenu' . $processo->getId() . ' > a.js-relatorio-processos + button.js-proc-imprimir'));
    }

    #[TestDox('Quem só vê a pasta (com Expediente): o relatório no cartão, sem o ⋮ do cabeçalho nem o Enviar')]
    public function testLeitorComExpediente(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07011345720258070007'));
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view', 'modules.expediente.view']);
        $this->logarComTenant($client, $leitor, $tenant);

        $secao = $this->abrir($client, $pasta)->filter('section.ps-processos');

        self::assertCount(0, $secao->filter('form.js-relatorio-enviar'));
        self::assertCount(1, $secao->filter('.ps-processo-menu > a.js-relatorio-processos'));
    }

    #[TestDox('Sem o módulo Expediente, o item do relatório não aparece (a rota daria 403)')]
    public function testSemExpedienteNaoVeORelatorio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pasta  = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, '07011345720258070007'));
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.processo.view']);
        $this->logarComTenant($client, $leitor, $tenant);

        self::assertCount(0, $this->abrir($client, $pasta)->filter('.js-relatorio-processos'));
    }
}
