<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\MetaDePastasDoDashboardController;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Aumentar o nível" do painel BlueJus Intelligence (desenho 01 VS 25.10.26, L1284-1325): o bloco só aparece
 * para quem gere o escritório e só com ritmo da meta; aplicar grava a meta do mês pelo POST de sempre.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(MetaDePastasDoDashboardController::class)]
#[Group('dashboard')]
final class DashboardInteligenciaNivelTelaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES_GESTOR = ['modules.expediente.view', 'resources.pasta.view', 'modules.bi.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function mesInteiro(): string
    {
        $hoje = new \DateTimeImmutable('today');

        return '/dashboard?data_de=' . $hoje->format('Y-m-01') . '&data_ate=' . $hoje->format('Y-m-t');
    }

    private function definirMeta(Tenant $tenant, User $autor, int $valor): void
    {
        $this->em()->persist(new DashboardMetaPastas($tenant, new \DateTimeImmutable('first day of this month'), $valor, $autor));
        $this->em()->flush();
    }

    private function pastaCriadaPor(Tenant $tenant, User $quem): void
    {
        $this->criarPasta($tenant)->setCriadoPor($quem);
        $this->em()->flush();
    }

    private function painel(string $url = ''): Crawler
    {
        $crawler = $this->client->request('GET', $url !== '' ? $url : $this->mesInteiro());
        self::assertResponseIsSuccessful();

        return $crawler->filter('#dbIaPainel > .db-ia-corpo');
    }

    private function postarMeta(int $valor, string $token = 'TOKEN_ajax'): void
    {
        $this->client->request('POST', '/dashboard/meta-pastas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['valor' => $valor], JSON_THROW_ON_ERROR));
    }

    /** @return list<int> */
    private function metasGravadas(Tenant $tenant): array
    {
        return array_map('intval', $this->em()->getConnection()->fetchFirstColumn('SELECT valor FROM dashboard_meta_pastas WHERE tenant_id = :t ORDER BY id', ['t' => $tenant->getId()]));
    }

    #[TestDox('Primário com meta 100 vê o bloco: 4 níveis (+10% escolhido), novas metas 100/110/120/135 e o botão de aplicar nos que mudam a meta')]
    public function testPrimarioVeOPlano(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana = $this->criarColaborador($tenant, 'Ana Souza');
        $this->definirMeta($tenant, $primario, 100);
        $this->pastaCriadaPor($tenant, $ana);
        $this->entrar($primario, $tenant);

        $plano = $this->painel()->filter('.db-ia-plano');

        self::assertCount(1, $plano);
        self::assertSame('Aumentar o nível', trim($plano->filter('.db-ia-plano-cab')->text()));
        self::assertStringContainsString('Por regras fixas', $plano->filter('.db-ia-plano-texto')->text());
        self::assertStringNotContainsString('A IA monta', $plano->text());
        $botoes = $plano->filter('.db-ia-niveis > .db-ia-nivel-btn');
        self::assertSame(['Manter', '+10%', '+20%', '+35%'], $botoes->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertSame(['false', 'true', 'false', 'false'], $botoes->each(static fn (Crawler $b): ?string => $b->attr('aria-pressed')));
        $metas = $plano->filter('.db-ia-plano-nivel')->each(static fn (Crawler $n): string => trim($n->filter('.db-ia-plano-valor')->first()->text()));
        self::assertSame(['100', '110', '120', '135'], $metas);
        $ocultos = $plano->filter('.db-ia-plano-nivel')->each(static fn (Crawler $n): bool => $n->attr('hidden') !== null);
        self::assertSame([true, false, true, true], $ocultos);
        $aplicar = $plano->filter('[data-ia-plano-aplicar]')->each(static fn (Crawler $b): string => (string) $b->attr('data-valor'));
        self::assertSame(['110', '120', '135'], $aplicar, 'Manter não grava a mesma meta');
        self::assertSame('/dashboard/meta-pastas', $plano->attr('data-url'));
        self::assertStringContainsString('Ana Souza', $plano->text());
    }

    #[TestDox('O XHR dos filtros traz o bloco também')]
    public function testXhr(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $this->definirMeta($tenant, $primario, 100);
        $this->entrar($primario, $tenant);

        $this->client->request('GET', $this->mesInteiro(), [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, (new Crawler((string) $this->client->getResponse()->getContent()))->filter('.db-ia-plano'));
    }

    #[TestDox('Sem meta, fora do mês inteiro ou filtrando por responsável: o bloco some (sem base, nada inventado)')]
    public function testSemBaseSome(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $this->entrar($primario, $tenant);
        self::assertCount(0, $this->painel()->filter('.db-ia-plano'), 'sem meta');

        $this->definirMeta($tenant, $primario, 100);
        self::assertCount(1, $this->painel()->filter('.db-ia-plano'));
        self::assertCount(0, $this->painel('/dashboard?data_de=2026-09-01&data_ate=2026-09-15')->filter('.db-ia-plano'), 'período que não é o mês corrente inteiro');
        self::assertCount(0, $this->painel($this->mesInteiro() . '&responsavel=' . $primario->getId())->filter('.db-ia-plano'), 'filtro de responsável');
    }

    #[TestDox('Quem não é primário (gestor com o Dashboard) não vê o bloco e leva 403 ao aplicar')]
    public function testNaoPrimario(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestor = $this->criarColaborador($tenant, 'Gestor Secundário', self::PERMISSOES_GESTOR);
        $this->definirMeta($tenant, $primario, 100);
        $this->entrar($gestor, $tenant);

        self::assertCount(1, $this->painel()->filter('.db-ia-meta-ritmo'), 'o selo continua (prova que o painel abriu)');
        self::assertCount(0, $this->painel()->filter('.db-ia-plano'));

        $this->postarMeta(120);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([100], $this->metasGravadas($tenant));
    }

    #[TestDox('Com pasta oculta pelo cadeado o bloco não aparece para quem não é primário (o plano deixaria escapar o total do escritório)')]
    public function testCadeado(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $gestora = $this->criarColaborador($tenant, 'Gestora Oculta', self::PERMISSOES_GESTOR);
        $livre   = $this->criarColaborador($tenant, 'Gestor Livre', self::PERMISSOES_GESTOR);
        $this->definirMeta($tenant, $primario, 4321);
        $oculta = $this->criarPasta($tenant);
        $this->trancar($oculta, $primario, [[$livre, NivelDeAcesso::Editar]]);

        foreach ([$gestora, $livre] as $quem) {
            $this->entrar($quem, $tenant);
            $this->painel();
            self::assertStringNotContainsString('db-ia-plano', (string) $this->client->getResponse()->getContent());
        }
        $this->entrar($gestora, $tenant);
        self::assertCount(0, $this->painel()->filter('.db-ia-meta-ritmo, .db-ia-plano'), 'com pasta oculta nem o selo nem o plano');
        $this->entrar($primario, $tenant);
        self::assertCount(1, $this->painel()->filter('.db-ia-plano'), 'o primário vê tudo');
    }

    #[TestDox('Aplicar: o POST da meta grava o valor do nível no mês, e o painel recalcula sobre a nova base')]
    public function testAplicarMudaAMeta(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $this->definirMeta($tenant, $primario, 100);
        $this->entrar($primario, $tenant);

        $this->postarMeta(120);

        self::assertResponseIsSuccessful();
        self::assertSame([100, 120], $this->metasGravadas($tenant));
        $metas = $this->painel()->filter('.db-ia-plano-nivel')->each(static fn (Crawler $n): string => trim($n->filter('.db-ia-plano-valor')->first()->text()));
        self::assertSame(['120', '132', '144', '162'], $metas);
    }

    #[TestDox('Valor fora de 1 a 9999 dá 400 e nada é gravado; CSRF inválido dá 403')]
    public function testValorInvalidoECsrf(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $this->definirMeta($tenant, $primario, 100);
        $this->entrar($primario, $tenant);

        foreach ([0, -5, 10000] as $valor) {
            $this->postarMeta($valor);
            self::assertResponseStatusCodeSame(400, (string) $valor);
        }
        $this->postarMeta(120, 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([100], $this->metasGravadas($tenant));
    }

    #[TestDox('Tenant: o plano de um escritório usa a meta dele; aplicar no A não mexe no B')]
    public function testTenant(): void
    {
        [$primarioA, $tenantA] = $this->criarEscritorio();
        [$primarioB, $tenantB] = $this->criarEscritorio();
        $this->definirMeta($tenantA, $primarioA, 100);
        $this->definirMeta($tenantB, $primarioB, 50);

        $this->entrar($primarioB, $tenantB);
        $this->postarMeta(60);
        $metas = $this->painel()->filter('.db-ia-plano-nivel')->each(static fn (Crawler $n): string => trim($n->filter('.db-ia-plano-valor')->first()->text()));

        self::assertSame(['60', '66', '72', '81'], $metas);
        self::assertSame([100], $this->metasGravadas($tenantA));
        self::assertSame([50, 60], $this->metasGravadas($tenantB));
    }
}
