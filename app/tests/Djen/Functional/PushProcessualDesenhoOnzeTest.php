<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Controller\DjenController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Arranjo do desenho 11 (Push Processual): abas, cartões do topo, filtros e tabela por filho direto,
 * e o que saiu da tela por não existir no desenho (cartão do AdminLTE, filtro Meio).
 */
#[CoversClass(DjenController::class)]
#[Group('djen')]
final class PushProcessualDesenhoOnzeTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    #[Test]
    public function publicacoesTemAbasKpisFiltrosETabelaDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->criarPublicacao($tenant, '940001', '50636766220224047000');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('nav.pp-abas > .pp-abas__trilho > a.pp-aba'), 'quatro abas, filhas diretas do trilho');
        self::assertCount(1, $crawler->filter('nav.pp-abas a.pp-aba.is-ativa[data-pp-aba="publicacoes"]'));
        self::assertCount(4, $crawler->filter('.pp-pagina > .pp-kpis > a.pp-kpi'));
        self::assertCount(1, $crawler->filter('.pp-pagina > section.pp-caixa--filtros > form.pp-filtros'));
        self::assertCount(1, $crawler->filter('.pp-pagina > section.pp-tabela > .pp-tabela__corpo > .pp-linha--cab'));
        self::assertCount(1, $crawler->filter('.pp-tabela__corpo > .pp-linha--dado.pp-linha--nova'), 'publicação não lida em negrito');
        self::assertCount(0, $crawler->filter('.pp-pagina .card, .pp-pagina .btn, .pp-pagina select[name="meio"]'), 'nada do AdminLTE nem o filtro Meio');
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="nao_lidas"] .pp-kpi__valor')->text()));
        self::assertSame('1', trim($crawler->filter('[data-pp-kpi="avulsas"] .pp-kpi__valor')->text()));
    }

    #[Test]
    public function filtroDeLeituraSeparaLidasDeNaoLidas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $lida = $this->criarPublicacao($tenant, '940002', '50636766220224047001');
        $lida->setLida(true);
        $this->criarPublicacao($tenant, '940003', '50636766220224047002');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual?leitura=lidas');
        self::assertCount(1, $crawler->filter('.pp-linha--dado'));
        self::assertCount(0, $crawler->filter('.pp-linha--nova'));
        self::assertCount(1, $crawler->filter('[data-pp-limpar]'), 'filtro ativo mostra Limpar filtros');

        $crawler = $client->request('GET', '/push-processual?leitura=nao_lidas');
        self::assertCount(1, $crawler->filter('.pp-linha--nova'));
    }

    #[Test]
    public function diarioDaOabEntraComoCascaComEstadoVazio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/diario');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.pp-abas a.pp-aba.is-ativa[data-pp-aba="diario"]'));
        self::assertCount(1, $crawler->filter('.pp-pagina > .pp-sigilo'));
        self::assertSame('Nenhuma publicação.', trim($crawler->filter('[data-pp-diario-vazio]')->text()));
    }

    #[Test]
    public function abaOabsMonitoradasFicaAcesaNaTelaDeOabs(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/oabs');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.pp-abas a.pp-aba.is-ativa[data-pp-aba="oabs"]'));
    }

    #[Test]
    public function abaOabsMostraFormularioETabelaDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com'), $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/oabs');

        self::assertCount(1, $crawler->filter('.pp-pagina > section[data-pp-oab-adicionar] > form.pp-oab-form'));
        self::assertCount(1, $crawler->filter('form.pp-oab-form select option[value="DF"]'), 'UF em lista, como no desenho');
        self::assertCount(0, $crawler->filter('.pp-pagina .card, .pp-pagina .form-control, .pp-pagina .table'));
        self::assertSame('Nenhuma OAB monitorada.', trim($crawler->filter('[data-pp-oab-tabela] .pp-vazio')->text()));
        self::assertCount(6, $crawler->filter('.pp-linha--cab > span'), 'OAB, apelido, status, publicações, desde, ações');
    }
}
