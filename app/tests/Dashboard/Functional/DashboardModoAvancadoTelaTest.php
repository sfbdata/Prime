<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Controller\PreferenciaDoDashboardController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * DASH-025 — Modo avançado do painel BlueJus Intelligence lembrado POR USUÁRIO no servidor
 * (`dashboard.ia_avancado`), pelo mesmo endpoint do menu ⋮.
 *
 * O ciclo que importa é o do usuário: grava pelo POST, recarrega a página e a tela volta no modo
 * gravado (classe no `.db-page` + interruptor marcado), inclusive no fragmento do XHR. A escolha
 * de um colega do mesmo escritório não chega à minha tela, nem a minha a outro escritório.
 * Que o painel de fato fica com 600px é smoke do dono; aqui se trava a regra de CSS.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(PreferenciaDoDashboardController::class)]
final class DashboardModoAvancadoTelaTest extends DashboardWebTestCase
{
    private const SWITCH = 'aside.db-ia-painel > .db-ia-sub > button.db-ia-av-switch[role="switch"]';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, array $corpo): void
    {
        $client->request(
            'POST',
            '/dashboard/preferencias',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            json_encode($corpo, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function noBanco(Tenant $tenant, User $usuario): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT chave, valor FROM preferencia_usuario WHERE tenant_id = :t AND user_id = :u ORDER BY chave',
            ['t' => $tenant->getId(), 'u' => $usuario->getId()],
        );

        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[$l['chave']] = json_decode((string) $l['valor'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $mapa;
    }

    /** @return array<string, mixed> */
    private function estadoDaTela(Crawler $crawler): array
    {
        return json_decode((string) $crawler->filter('section.db-page')->attr('data-preferencias'), true, 512, JSON_THROW_ON_ERROR);
    }

    #[TestDox('Sem escolha: .db-page sem classe, interruptor desligado e estado "desligado" no data-preferencias')]
    public function testPadraoDesligado(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
        self::assertSame('false', $crawler->filter(self::SWITCH)->attr('aria-checked'));
        self::assertSame('desligado', $this->estadoDaTela($crawler)['dashboard.ia_avancado']);
    }

    #[TestDox('Ligar pelo endpoint grava no banco e a página RECARREGADA volta com o modo ligado (classe + interruptor)')]
    public function testGravadoERelidoDepoisDoReload(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => 'ligado']);

        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ligado', $resposta['preferencias']['dashboard.ia_avancado']);
        self::assertSame('db-page--ia-avancado', $resposta['classes']);
        self::assertSame(['dashboard.ia_avancado' => 'ligado'], $this->noBanco($tenant, $user));

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('section.db-page.db-page--ia-avancado')->count());
        self::assertSame('true', $crawler->filter(self::SWITCH)->attr('aria-checked'));
        self::assertSame('ligado', $this->estadoDaTela($crawler)['dashboard.ia_avancado']);

        // desligar volta tudo — e o reload confirma
        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => 'desligado']);
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/dashboard');
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
        self::assertSame('false', $crawler->filter(self::SWITCH)->attr('aria-checked'));
    }

    #[TestDox('O fragmento do XHR (filtro) traz o interruptor no estado gravado — o painel é trocado a cada filtro')]
    public function testXhrMantemOEstado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);
        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => 'ligado']);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $switch = $crawler->filter('.db-ia > ' . self::SWITCH);
        self::assertCount(1, $switch);
        self::assertSame('true', $switch->attr('aria-checked'));
    }

    #[TestDox('Valor fora da lista (booleano e o "1" do localStorage do protótipo) dá 400 e não grava')]
    public function testValorForaDaLista(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => true]);
        self::assertResponseStatusCodeSame(400);
        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => '1']);
        self::assertResponseStatusCodeSame(400);

        self::assertSame([], $this->noBanco($tenant, $user));
    }

    #[TestDox('Um colega do MESMO escritório não herda o meu Modo avançado (a escolha é por usuário)')]
    public function testColegaNaoHerda(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => 'ligado']);
        self::assertResponseIsSuccessful();

        // irmã que prova o alvo: eu vejo ligado
        $crawler = $client->request('GET', '/dashboard');
        self::assertSame('true', $crawler->filter(self::SWITCH)->attr('aria-checked'));

        // As requisições anteriores resetaram o EntityManager (services_resetter): o $tenant em
        // mãos está desanexado e o flush do UserTenant do colega o trataria como entidade nova.
        $tenant = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertNotNull($tenant);
        $colega = $this->criarUsuarioComPermissaoBi($tenant);
        $this->logarComTenant($client, $colega, $tenant);
        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
        self::assertSame('false', $crawler->filter(self::SWITCH)->attr('aria-checked'));
        self::assertSame([], $this->noBanco($tenant, $colega));
        self::assertSame(['dashboard.ia_avancado' => 'ligado'], $this->noBanco($tenant, $user));
    }

    #[TestDox('"Restaurar padrão" do menu da tabela não desliga o Modo avançado (no desenho é outra chave)')]
    public function testRestaurarNaoDesliga(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->postar($client, ['chave' => 'dashboard.ia_avancado', 'valor' => 'ligado']);
        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => 'confortavel']);

        $this->postar($client, ['restaurar' => true]);

        self::assertResponseIsSuccessful();
        $resposta = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('db-page--ia-avancado', $resposta['classes']);
        self::assertSame(['dashboard.ia_avancado' => 'ligado'], $this->noBanco($tenant, $user));
    }

    #[TestDox('O CSS leva o painel a 600px e mostra o bloco avançado só com db-page--ia-avancado (460px no básico)')]
    public function testCssDoModo(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/dashboard-inteligencia.css');

        self::assertStringContainsString('width: min(460px, 100vw);', $css);
        self::assertStringContainsString('.db-page.db-page--ia-avancado .db-ia-painel { width: min(600px, 100vw); }', $css);
        self::assertStringContainsString('.db-page .db-ia-av { display: none; }', $css);
        self::assertStringContainsString('.db-page.db-page--ia-avancado .db-ia-av { display: block; }', $css);
        self::assertStringContainsString('.db-page .db-ia-av-switch[aria-checked="true"] .db-ia-switch-bola { transform: translateX(14px); }', $css);
        self::assertStringNotContainsString('.db-ia-av[open]', $css, 'não sobra regra do <details> antigo');
    }
}
