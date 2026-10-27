<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\PreferenciaDoDashboardController;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Setas de tendência" no grupo "Exibição" do menu ⋮ (desenho "01 - Dashboard 1.2.2", dc L666-671:
 * `alternarSetas` grava `semSetas`; padrão ligado). Desligado, o desenho some com a seta das
 * células (`setaWrap: display:none`, `padSeta: 0`) e com a pílula de percentual (`tendCor:
 * display:none`), inclusive a da linha de Total e a dos cards do celular.
 *
 * A comparação continua contra o período anterior equivalente: a preferência só esconde por
 * classe no `.db-page`, o HTML das setas e pílulas segue o mesmo. Que de fato some na tela é
 * smoke do dono.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(PreferenciaDoDashboardController::class)]
#[Group('dashboard')]
final class DashboardSetasExibicaoTelaTest extends DashboardWebTestCase
{
    private const MENU = 'section.db-page > .db-pref > .db-pref-menu[role="menu"]';
    private const PERIODO = 'data_de=2026-09-01&data_ate=2026-09-30';

    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gravarSetas(KernelBrowser $client, string $valor): void
    {
        $client->request(
            'POST',
            '/dashboard/preferencias',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            json_encode(['chave' => 'dashboard.setas', 'valor' => $valor], JSON_THROW_ON_ERROR),
        );
    }

    /** Foto da véspera do período: dá pílula de Vencidas e Prazos na linha de Total. */
    private function fotografarVespera(Tenant $tenant, User ...$pessoas): void
    {
        $contagens = [];
        foreach ($pessoas as $pessoa) {
            $contagens[(int) $pessoa->getId()] = ['metas_ativas' => 10, 'demandas_ativas' => 4, 'metas_vencidas' => 5, 'prazos_proximos' => 0];
        }

        static::getContainer()->get(DashboardFotoRepository::class)
            ->gravar($tenant, new \DateTimeImmutable('2026-08-31'), $contagens);
    }

    private function classeDaPagina(Crawler $crawler): string
    {
        return (string) $crawler->filter('section.db-page')->attr('class');
    }

    #[TestDox('No grupo Exibição, logo depois de Animações, com o rótulo e o ícone do desenho, ligado por padrão')]
    public function testInterruptorNoLugarDoDesenhoLigadoPorPadrao(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $caminho = self::MENU . ' > button[data-pref-chave="dashboard.animacoes"] + .db-pref-div + .db-pref-secao + button.db-pref-item[data-pref-chave="dashboard.setas"]';
        self::assertCount(1, $crawler->filter($caminho));
        self::assertSame('Exibição', trim($crawler->filter(self::MENU . ' > button[data-pref-chave="dashboard.animacoes"] + .db-pref-div + .db-pref-secao')->text()));

        $setas = $crawler->filter($caminho);
        self::assertSame('menuitemcheckbox', $setas->attr('role'));
        self::assertSame('true', $setas->attr('aria-checked'));
        self::assertSame('ligadas', $setas->attr('data-pref-liga'));
        self::assertSame('desligadas', $setas->attr('data-pref-desliga'));
        self::assertCount(1, $setas->filter('button > i.bi-arrow-up-right'));
        self::assertSame('Setas de tendência', trim($setas->filter('button > .db-pref-rotulo')->text()));
        self::assertStringNotContainsString('db-page--sem-setas', $this->classeDaPagina($crawler));
    }

    #[TestDox('Desligar pelo endpoint grava e a próxima carga nasce sem setas, com o interruptor desligado')]
    public function testDesligarGravaERespeitaNaCarga(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$gestora, $tenant] = $this->criarGestorLogado($client);

        $this->gravarSetas($client, 'desligadas');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('db-page--sem-setas', $this->classeDaPagina($crawler));
        self::assertCount(1, $crawler->filter(self::MENU . ' > button[data-pref-chave="dashboard.setas"][aria-checked="false"]'));
        $estado = json_decode((string) $crawler->filter('section.db-page')->attr('data-preferencias'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('desligadas', $estado['dashboard.setas']);
        self::assertSame('desligadas', $this->repo()->valoresDoUsuario($tenant, $gestora, ['dashboard.setas'])['dashboard.setas'] ?? null);
    }

    #[TestDox('Religar volta a tela padrão: o .db-page perde db-page--sem-setas')]
    public function testReligarVoltaAoPadrao(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $this->gravarSetas($client, 'desligadas');
        $this->gravarSetas($client, 'ligadas');
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/dashboard');

        self::assertStringNotContainsString('db-page--sem-setas', $this->classeDaPagina($crawler));
        self::assertCount(1, $crawler->filter(self::MENU . ' > button[data-pref-chave="dashboard.setas"][aria-checked="true"]'));
    }

    #[TestDox('Desligadas, setas e pílulas (do Total e do celular) continuam no HTML, contra o período anterior; somem só por classe')]
    public function testDesligadasNaoMudamOCalculo(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana = $this->criarColaborador($tenant, 'Ana Setas');
        $this->fotografarVespera($tenant, $gestora, $ana);
        $this->repo()->gravar($tenant, $gestora, 'dashboard.setas', 'desligadas');

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('db-page--sem-setas', $this->classeDaPagina($crawler));
        self::assertGreaterThan(0, $crawler->filter('table > tbody .db-seta')->count(), 'a seta das células segue no HTML');
        $pilulaVencidas = $crawler->filter('table > tfoot > tr.db-total-row > td[data-coluna="metas_vencidas"] > .db-total-cel > .db-tend');
        self::assertCount(1, $pilulaVencidas, 'a pílula do Total segue no HTML');
        self::assertStringContainsString('(10 → 0)', (string) $pilulaVencidas->attr('title'), 'compara com o período anterior');
        self::assertGreaterThan(0, $crawler->filter('.db-cel-card--total .db-tend')->count(), 'a pílula do card de Total do celular segue no HTML');
    }

    #[TestDox('O CSS esconde seta, pílula e espaçador, e tira o recuo da célula, pela classe db-page--sem-setas')]
    public function testCssEscondeSetasEPilulas(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/dashboard.css');

        self::assertStringContainsString('.db-page.db-page--sem-setas .db-seta,', $css);
        self::assertStringContainsString('.db-page.db-page--sem-setas .db-tend,', $css);
        self::assertStringContainsString('.db-page.db-page--sem-setas .db-total-espaco { display: none !important; }', $css);
        self::assertStringContainsString('.db-page.db-page--sem-setas .db-cel--seta { padding-left: 0; }', $css);
    }

    #[TestDox('O menu aplica e tira a classe na hora, sem recarregar (contrato do dashboard-preferencias.js)')]
    public function testJsAplicaAClasseNaHora(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/dashboard-preferencias.js');

        self::assertStringContainsString("if (e[CH_SETAS] === 'desligadas') { lista.push('db-page--sem-setas'); }", $js);
    }

    #[TestDox('As setas desligadas de um colega do mesmo escritório não chegam à minha tela')]
    public function testPreferenciaDoColegaNaoVaza(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Sem Setas');
        $this->repo()->gravar($tenant, $colega, 'dashboard.setas', 'desligadas');

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('db-page--sem-setas', $this->classeDaPagina($crawler));
    }
}
