<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\DemandasController;
use App\Pasta\Controller\DemandasLinhaController;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Minhas Demandas no desenho 15 (`docs/design/redesign-30-10-26/15 - Demandas - VS 30.10.26.dc.html`): a Lista do
 * Expediente sem o trilho, só com as pastas de que a pessoa é responsável, respeitando o cadeado; os endpoints
 * próprios da tela (colunas, CSV, sugestões e ações da linha) com o gate do módulo `pastas` e o isolamento entre
 * escritórios; e a Nova demanda, que abre a pasta com quem cria como responsável.
 */
#[CoversClass(DemandasController::class)]
#[CoversClass(DemandasLinhaController::class)]
final class DemandasControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . DemandasController::CSRF_LISTA;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // Várias requisições por teste: sem reiniciar o kernel, o armazenamento de CSRF do teste continua valendo.
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    // ── Acesso ──────────────────────────────────────────────────────────

    #[TestDox('GET /demandas sem autenticação redireciona para o login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $this->client->request('GET', '/demandas');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /demandas sem o módulo de pastas desvia para o início (não para o login)')]
    public function testSemModuloPastasBloqueia(): void
    {
        [, $tenant] = $this->criarAdmin();
        $semModulo  = $this->criarColaborador($tenant, []);
        $this->logarComTenant($this->client, $semModulo, $tenant);

        $this->client->request('GET', '/demandas');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ── Estrutura do desenho 15 ─────────────────────────────────────────

    #[TestDox('a tela tem o título com a caixa de entrada, a barra do acervo e a Lista do Expediente, sem trilho')]
    public function testEstruturaDoDesenho(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1.exp-titulo > .exp-titulo-icone > i.bi-inbox-fill'));
        self::assertCount(1, $crawler->filter('h1.exp-titulo > .exp-titulo-icone > i.bi-folder-fill'));
        self::assertStringContainsString('Minhas Demandas', $crawler->filter('h1.exp-titulo')->text());
        self::assertCount(1, $crawler->filter('#demandas-painel > .exp-painel > .exp-barra > h2.exp-barra-titulo'));
        self::assertStringContainsString('Todas as demandas', $crawler->filter('.exp-barra > h2.exp-barra-titulo')->text());

        $nova = $crawler->filter('.exp-painel > .exp-barra > button.exp-nova-demanda[data-bs-target="#modalNovaPasta"]');
        self::assertCount(1, $nova);
        self::assertStringContainsString('Nova demanda', $nova->text());
        self::assertCount(1, $nova->filter('i.bi-plus-lg'));
        self::assertStringContainsString('você como responsável', (string) $nova->attr('data-tip'));

        self::assertCount(1, $crawler->filter('.exp-painel > #pastas-resultado > #pastasView'));
        self::assertCount(0, $crawler->filter('#expedienteSidebar, .expediente-sidebar, .exp-trilho'));
        self::assertCount(1, $crawler->filter('#modalNovaPasta form > input[type="hidden"][name="criador_responsavel"][value="1"]'));
        self::assertStringNotContainsString('Importar em lote', (string) $this->client->getResponse()->getContent());
        self::assertSame('exp-body exp-demandas', $this->classesDoCorpo($crawler));
    }

    #[TestDox('o Visualizar não oferece o painel de marcadores (a tela não tem trilho)')]
    public function testVisualizarSemPainelDeMarcadores(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.js-vis-painel'));
    }

    #[TestDox('colunas padrão do desenho 15: NUP, Cliente, Ação, Prioridade, Metas e a "Mod." da tela antiga ligadas; o resto desligado')]
    public function testColunasPadrao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->request('GET', '/demandas');

        $tabela = $crawler->filter('#tabelaPastas');
        self::assertCount(1, $tabela);
        self::assertSame(
            ['nup' => true, 'cliente' => true, 'reu' => false, 'acao' => true, 'prio' => true, 'resp' => false, 'metas' => true, 'marc' => false, 'sit' => false, 'mod' => true],
            json_decode((string) $tabela->attr('data-colunas-padrao'), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('demandas_colunas:', $tabela->attr('data-prefixo-colunas-local'));
        self::assertCount(1, $crawler->filter('#tabelaPastas thead th[data-col="mod"]'));
        self::assertStringContainsString('Hoje', $crawler->filter('#tabelaPastas tbody td[data-col="mod"]')->text());
    }

    #[TestDox('sem ordenação escolhida, as urgentes vêm primeiro (comportamento histórico das Demandas)')]
    public function testOrdemPadraoUrgentesPrimeiro(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $normal  = $this->criarPasta($tenant, $user);
        $urgente = $this->criarPasta($tenant, $user);
        $urgente->setPrioridade(PrioridadePasta::Urgente);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->request('GET', '/demandas');

        self::assertStringContainsString((string) $urgente->getNup(), $crawler->filter('#tabelaPastas tbody tr')->first()->text());
    }

    #[TestDox('o CSS da tela zebra as linhas (#ffffff e #eef3f7, borda #eef2f5) só sob .exp-demandas')]
    public function testZebraNoCss(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/demandas-lista.css');

        self::assertStringContainsString('.exp-demandas #tabelaPastas tbody tr > td { background: #ffffff; border-bottom: 1px solid #eef2f5; }', $css);
        self::assertStringContainsString('.exp-demandas #tabelaPastas tbody tr:nth-child(even) > td { background: #eef3f7; }', $css);
    }

    // ── Só as minhas, respeitando o cadeado e o escritório ─────────────

    #[TestDox('lista só as pastas de que sou responsável; as de outra pessoa e as de outro escritório ficam fora')]
    public function testSoAsMinhasEDoMeuEscritorio(): void
    {
        [$user, $tenant]   = $this->criarAdmin();
        [$outro, $tenantB] = $this->criarAdmin();
        $colega            = $this->criarColaborador($tenant, ['modules.pastas.view']);
        $minha             = $this->criarPasta($tenant, $user);
        $doColega          = $this->criarPasta($tenant, $colega);
        $semResponsavel    = $this->criarPasta($tenant, null);
        $deOutroEscritorio = $this->criarPasta($tenantB, $outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $minha->getNup(), $html);
        self::assertStringNotContainsString((string) $doColega->getNup(), $html);
        self::assertStringNotContainsString((string) $semResponsavel->getNup(), $html);
        self::assertStringNotContainsString((string) $deOutroEscritorio->getNup(), $html);
    }

    #[TestDox('pasta minha escondida pelo cadeado (ocultar para todos) não aparece para o colaborador')]
    public function testCadeadoEscondeDaLista(): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $colaborador      = $this->criarColaborador($tenant, ['modules.pastas.view']);
        $visivel          = $this->criarPasta($tenant, $colaborador);
        $trancada         = $this->criarPasta($tenant, $colaborador);
        $this->em()->persist(new CadeadoDaPasta($tenant, $trancada, ModoDoCadeado::Todos, $admin, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->logarComTenant($this->client, $colaborador, $tenant);

        $this->client->xmlHttpRequest('GET', '/demandas');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $visivel->getNup(), $html);
        self::assertStringNotContainsString((string) $trancada->getNup(), $html);
        self::assertStringContainsString('1 demanda(s) encontrada(s)', $html);
    }

    #[TestDox('filtro Responsável com outra pessoa devolve vazio (a lista é sempre a minha)')]
    public function testFiltroResponsavelDeOutraPessoaDevolveVazio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarColaborador($tenant, ['modules.pastas.view']);
        $this->criarPasta($tenant, $user);
        $this->criarPasta($tenant, $colega);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/demandas', ['responsavel' => (string) $colega->getId()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma demanda encontrada', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('filtros de prioridade e situação estreitam as minhas demandas')]
    public function testFiltrosEstreitam(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $urgente         = $this->criarPasta($tenant, $user);
        $urgente->setPrioridade(PrioridadePasta::Urgente);
        $arquivada = $this->criarPasta($tenant, $user);
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/demandas');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $urgente->getNup(), $html, 'Todas (padrão) traz ativas e arquivadas');
        self::assertStringContainsString((string) $arquivada->getNup(), $html);

        $this->client->xmlHttpRequest('GET', '/demandas', ['status' => 'ativo']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $urgente->getNup(), $html);
        self::assertStringNotContainsString((string) $arquivada->getNup(), $html);

        $this->client->xmlHttpRequest('GET', '/demandas', ['prioridade' => 'urgente']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $urgente->getNup(), $html);
        self::assertStringNotContainsString((string) $arquivada->getNup(), $html);
    }

    #[TestDox('XHR devolve só o fragmento do acervo, com o resumo "N demanda(s) encontrada(s)"')]
    public function testXhrDevolveFragmento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/demandas');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $pasta->getNup(), $html);
        self::assertStringContainsString('1 demanda(s) encontrada(s)', $html);
        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('exp-titulo-icone', $html);
    }

    #[TestDox('sem resultado, a linha do resumo (colunas e Visualizar) fica e o vazio diz o texto do desenho (L.269)')]
    public function testVazioMantemLinhaDoResumo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->criarPasta($tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->xmlHttpRequest('GET', '/demandas', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        $vista = '#pastas-resultado > #pastasView.pastas-view--vazio';
        self::assertCount(1, $crawler->filter($vista . ' > .exp-topo > .exp-resumo > button.js-colunas'));
        self::assertCount(1, $crawler->filter($vista . ' > .exp-topo > .exp-controles > .exp-visualizar'));
        self::assertStringContainsString('Nenhuma demanda encontrada', $crawler->filter($vista . ' > .exp-topo > .exp-resumo')->text());
        self::assertSame('Nenhuma pasta encontrada com esses filtros.', $crawler->filter($vista . ' > .exp-vazio')->text());
        self::assertCount(0, $crawler->filter('#tabelaPastas tbody tr.pasta-row-link'));
    }

    #[TestDox('sem nenhuma demanda (sem filtro), o vazio também é o do desenho')]
    public function testVazioSemFiltro(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        $crawler = $this->client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
        self::assertSame('Nenhuma pasta encontrada com esses filtros.', $crawler->filter('#pastasView.pastas-view--vazio > .exp-vazio')->text());
    }

    #[TestDox('o CSS esconde tabela, lista e cartões no vazio das Demandas')]
    public function testCssDoVazio(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/demandas-lista.css');

        self::assertStringContainsString('.exp-demandas .pastas-view--vazio .pastas-tabela-wrap,', $css);
        self::assertStringContainsString('.exp-demandas .pastas-view--vazio .exp-cartoes { display: none !important; }', $css);
    }

    // ── Endpoints da tela: gate do módulo e escritório ─────────────────

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function endpointsDaTela(): iterable
    {
        yield 'colunas'    => ['POST', '/demandas/colunas'];
        yield 'csv'        => ['GET', '/demandas/exportar.csv'];
        yield 'sugestoes'  => ['GET', '/demandas/nova-pasta/sugestoes'];
        yield 'nome'       => ['POST', '/demandas/pasta/{id}/nome'];
        yield 'trancar'    => ['POST', '/demandas/pasta/{id}/trancar'];
        yield 'destaque'   => ['POST', '/demandas/pasta/{id}/destaque'];
        yield 'marcadores' => ['POST', '/demandas/pasta/{id}/marcadores'];
    }

    #[DataProvider('endpointsDaTela')]
    #[TestDox('endpoint $url ($metodo) sem o módulo de pastas é recusado com 403')]
    public function testEndpointSemModuloRecusa(string $metodo, string $url): void
    {
        [$admin, $tenant] = $this->criarAdmin();
        $semModulo        = $this->criarColaborador($tenant, []);
        $pasta            = $this->criarPasta($tenant, $semModulo);
        $this->logarComTenant($this->client, $semModulo, $tenant);

        $this->client->xmlHttpRequest($metodo, str_replace('{id}', (string) $pasta->getId(), $url), ['_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('gravar colunas guarda na chave própria demandas.colunas, não na do Expediente')]
    public function testGravarColunasNaChavePropria(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);
        $mapa = ['nup' => true, 'cliente' => true, 'reu' => true, 'acao' => false, 'prio' => true, 'resp' => true, 'metas' => false, 'marc' => false, 'sit' => true];

        $this->client->request('POST', '/demandas/colunas', [], [], [
            'HTTP_X-CSRF-Token'     => self::TOKEN,
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'CONTENT_TYPE'          => 'application/json',
        ], json_encode(['colunas' => $mapa], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $valores = static::getContainer()->get(PreferenciaDoUsuarioRepository::class)
            ->valoresDoUsuario($tenant, $user, ['demandas.colunas', 'expediente.colunas']);
        self::assertSame($mapa, $valores['demandas.colunas'] ?? null);
        self::assertArrayNotHasKey('expediente.colunas', $valores);
    }

    #[TestDox('gravar colunas com token errado é recusado')]
    public function testGravarColunasTokenErrado(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/demandas/colunas', [], [], [
            'HTTP_X-CSRF-Token' => 'errado',
            'CONTENT_TYPE'      => 'application/json',
        ], '{"colunas":null}');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('marcadores numa pasta de outro escritório devolvem 404')]
    public function testMarcadoresDeOutroEscritorio(): void
    {
        [$user, $tenant]   = $this->criarAdmin();
        [$outro, $tenantB] = $this->criarAdmin();
        $alheia            = $this->criarPasta($tenantB, $outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/demandas/pasta/' . $alheia->getId() . '/marcadores', ['_token' => 'TOKEN_pasta_marcadores_' . $alheia->getId()]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('destacar uma pasta de outro escritório não funciona')]
    public function testDestaqueDeOutroEscritorio(): void
    {
        [$user, $tenant]   = $this->criarAdmin();
        [$outro, $tenantB] = $this->criarAdmin();
        $alheia            = $this->criarPasta($tenantB, $outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/demandas/pasta/' . $alheia->getId() . '/destaque', ['cor' => 'rosa', '_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('editar o nome rápido (F2) numa pasta de outro escritório devolve 404 e não grava')]
    public function testNomeDeOutroEscritorio(): void
    {
        [$user, $tenant]   = $this->criarAdmin();
        [$outro, $tenantB] = $this->criarAdmin();
        $alheia            = $this->criarPasta($tenantB, $outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/demandas/pasta/' . $alheia->getId() . '/nome', ['nome' => 'INVASOR', '_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        // SQL direto: o filtro de escritório do Doctrine, ligado pela requisição, esconderia a pasta alheia.
        self::assertSame('CLIENTE TESTE', $this->em()->getConnection()->fetchOne('SELECT nome_cliente FROM pasta WHERE id = ?', [$alheia->getId()]));
    }

    #[TestDox('trancar uma pasta de outro escritório devolve 404 e não cria cadeado')]
    public function testTrancarDeOutroEscritorio(): void
    {
        [$user, $tenant]   = $this->criarAdmin();
        [$outro, $tenantB] = $this->criarAdmin();
        $alheia            = $this->criarPasta($tenantB, $outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/demandas/pasta/' . $alheia->getId() . '/trancar', ['_token' => self::TOKEN]);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cadeado WHERE pasta_id = ?', [$alheia->getId()]));
    }

    #[TestDox('o CSV traz só as minhas demandas')]
    public function testCsvSoAsMinhas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->criarColaborador($tenant, ['modules.pastas.view']);
        $minha           = $this->criarPasta($tenant, $user);
        $doColega        = $this->criarPasta($tenant, $colega);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('GET', '/demandas/exportar.csv', ['colunas' => 'nup,cliente']);

        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $minha->getNup(), $csv);
        self::assertStringNotContainsString((string) $doColega->getNup(), $csv);
    }

    // ── Nova demanda ────────────────────────────────────────────────────

    #[TestDox('Nova demanda (criador_responsavel=1) abre a pasta com quem cria como responsável')]
    public function testNovaDemandaCriaComCriadorResponsavel(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/nova', ['nome_cliente' => 'Cliente Demanda ' . uniqid(), 'criador_responsavel' => '1', 'confirmar' => '1']);

        self::assertResponseIsSuccessful();
        $json  = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $json['status']);
        $this->em()->clear();
        $pasta = $this->em()->getRepository(Pasta::class)->findOneBy(['tenant' => $tenant->getId(), 'nup' => $json['nup']]);
        self::assertNotNull($pasta);
        self::assertSame($user->getId(), $pasta->getResponsavel()?->getId());
    }

    #[TestDox('Nova Pasta sem o campo (Expediente) continua sem responsável')]
    public function testNovaPastaSemCampoFicaSemResponsavel(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/nova', ['nome_cliente' => 'Cliente Pasta ' . uniqid(), 'confirmar' => '1']);

        self::assertResponseIsSuccessful();
        $json  = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->em()->clear();
        $pasta = $this->em()->getRepository(Pasta::class)->findOneBy(['tenant' => $tenant->getId(), 'nup' => $json['nup']]);
        self::assertNotNull($pasta);
        self::assertNull($pasta->getResponsavel());
    }

    #[TestDox('sugestões da Nova demanda devolvem o próximo número e os clientes do escritório')]
    public function testSugestoesDaNovaDemanda(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('GET', '/demandas/nova-pasta/sugestoes');

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('proximoNumero', $json);
        self::assertArrayHasKey('clientes', $json);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    private function classesDoCorpo(Crawler $crawler): string
    {
        $classes = array_values(array_filter(explode(' ', (string) $crawler->filter('body')->attr('class')), static fn (string $c): bool => in_array($c, ['exp-body', 'exp-demandas'], true)));

        return implode(' ', $classes);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $em     = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Tenant Demandas ' . uniqid());
        $em->persist($tenant);

        $user = $this->novoUsuario('Admin Demandas');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return [$user, $tenant];
    }

    /** @param string[] $permissoes */
    private function criarColaborador(Tenant $tenant, array $permissoes): User
    {
        $em   = $this->em();
        $user = $this->novoUsuario('Colaborador Demandas');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($permissoes as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail('demandas_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, ?User $responsavel): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('DEM' . strtoupper(substr(uniqid(), -7)));
        $pasta->setNomeCliente('Cliente Teste');
        $pasta->setResponsavel($responsavel);
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }
}
