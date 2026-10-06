<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\PreferenciaDoDashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * POST /dashboard/preferencias (`dashboard_preferencias_salvar`), o menu ⋮ da tabela Desempenho.
 *
 * Guardas: módulo `bi` (403), CSRF do header `X-CSRF-Token` (403), catálogo fechado (400). O dono
 * da preferência é SEMPRE o usuário da sessão no escritório da sessão — o teste manda um `user_id`
 * de outra pessoa no corpo para provar que é ignorado.
 */
#[CoversClass(PreferenciaDoDashboardController::class)]
final class PreferenciaDoDashboardControllerTest extends DashboardWebTestCase
{
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
    private function postar(KernelBrowser $client, array $corpo, string $token = 'TOKEN_ajax'): void
    {
        $client->request(
            'POST',
            '/dashboard/preferencias',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode($corpo, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function resposta(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> chave => valor decodificado */
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

    private function totalNoBanco(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM preferencia_usuario');
    }

    #[TestDox('Grava a escolha do usuário logado neste escritório e devolve o estilo completo + as classes')]
    public function testSalva(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => 'confortavel']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'preferencias' => [
                'dashboard.densidade'       => 'confortavel',
                'dashboard.animacoes'       => 'ligadas',
                'dashboard.setas'           => 'ligadas',
                'dashboard.colunas_ocultas' => [],
                'dashboard.sons'            => 'ligados',
            ],
            'classes' => 'db-page--confortavel',
        ], $this->resposta($client));
        self::assertSame(['dashboard.densidade' => 'confortavel'], $this->noBanco($tenant, $user));
    }

    #[TestDox('Sons desligados: grava a escolha e devolve a classe db-page--sem-som (o calendário fica mudo)')]
    public function testSalvaSonsDesligados(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.sons', 'valor' => 'desligados']);

        self::assertResponseIsSuccessful();
        $resposta = $this->resposta($client);
        self::assertSame('desligados', $resposta['preferencias']['dashboard.sons']);
        self::assertSame('db-page--sem-som', $resposta['classes']);
        self::assertSame(['dashboard.sons' => 'desligados'], $this->noBanco($tenant, $user));
    }

    #[TestDox('Sons com valor fora da lista (booleano do protótipo) dá 400 e não grava nada')]
    public function testSonsValorForaDaLista(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.sons', 'valor' => false]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Colunas ocultas: grava normalizado e a escolha repetida não duplica a linha')]
    public function testColunasIdempotente(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.colunas_ocultas', 'valor' => ['prazos', 'cargo']]);
        self::assertResponseIsSuccessful();
        $this->postar($client, ['chave' => 'dashboard.colunas_ocultas', 'valor' => ['cargo', 'prazos', 'cargo']]);
        self::assertResponseIsSuccessful();

        self::assertSame('db-oculta--cargo db-oculta--prazos', $this->resposta($client)['classes']);
        self::assertSame(['dashboard.colunas_ocultas' => ['cargo', 'prazos']], $this->noBanco($tenant, $user));
    }

    #[TestDox('Restaurar padrão apaga os ajustes do usuário e devolve o padrão sem classe')]
    public function testRestaurar(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.setas', 'valor' => 'desligadas']);
        $this->postar($client, ['chave' => 'dashboard.animacoes', 'valor' => 'reduzidas']);
        self::assertCount(2, $this->noBanco($tenant, $user));

        $this->postar($client, ['restaurar' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame('', $this->resposta($client)['classes']);
        self::assertSame([], $this->noBanco($tenant, $user));
    }

    #[TestDox('Token CSRF inválido dá 403 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => 'confortavel'], 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Sem o header de CSRF dá 403 e não grava nada')]
    public function testSemCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $client->request('POST', '/dashboard/preferencias', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['chave' => 'dashboard.densidade', 'valor' => 'confortavel'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Chave fora da lista dá 400 e não grava nada')]
    public function testChaveForaDaLista(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.zerar', 'valor' => true]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Valor fora da lista (inclusive JSON arbitrário) dá 400 e não grava nada')]
    public function testValorForaDaLista(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => ['qualquer' => 'objeto']]);
        self::assertResponseStatusCodeSame(400);
        $this->postar($client, ['chave' => 'dashboard.colunas_ocultas', 'valor' => ['metas', 'metas_ativas', 'metas_vencidas', 'prazos', 'demandas', 'demandas_ativas', 'pastas_criadas']]);
        self::assertResponseStatusCodeSame(400);
        $this->postar($client, ['chave' => 'dashboard.densidade']);
        self::assertResponseStatusCodeSame(400);

        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Corpo que não é JSON dá 400')]
    public function testCorpoInvalido(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $this->criarGestorLogado($client);

        $client->request('POST', '/dashboard/preferencias', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], 'nao-e-json');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('Um user_id no corpo é ignorado: grava para quem está logado, nunca para o colega')]
    public function testUserIdDoCorpoIgnorado(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega do Lado');

        $this->postar($client, ['chave' => 'dashboard.setas', 'valor' => 'desligadas', 'user_id' => $colega->getId(), 'tenant_id' => 999999]);

        self::assertResponseIsSuccessful();
        self::assertSame(['dashboard.setas' => 'desligadas'], $this->noBanco($tenant, $user));
        self::assertSame([], $this->noBanco($tenant, $colega));
    }

    #[TestDox('Sem o módulo bi dá 403 e não grava nada (a mesma porta do Dashboard)')]
    public function testSemModuloBi(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $semBi  = $this->criarColaborador($tenant, 'Sem Acesso Bi');
        $this->logarComTenant($client, $semBi, $tenant);

        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => 'confortavel']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalNoBanco());
    }

    #[TestDox('O mesmo usuário em dois escritórios: gravar no escritório A não muda o estilo no B')]
    public function testOutroTenantNaoEnxerga(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenantA] = $this->criarGestorLogado($client);

        // O mesmo usuário com vínculo e acesso bi num segundo escritório.
        $tenantB  = $this->criarTenant();
        $gestorB  = $this->criarUsuarioComPermissaoBi($tenantB);
        $vinculoB = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $gestorB, 'tenant' => $tenantB]);
        $novo     = new UserTenant($user, $tenantB);
        $novo->setTenantRole($vinculoB->getTenantRole());
        $this->em()->persist($novo);
        $this->em()->flush();

        $this->postar($client, ['chave' => 'dashboard.densidade', 'valor' => 'confortavel']);
        self::assertResponseIsSuccessful();

        // Troca para o escritório B: a tela abre no padrão, sem a classe gravada no A.
        $this->logarComTenant($client, $user, $tenantB);
        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('section.db-page.db-page--confortavel')->count());
        self::assertSame([], $this->noBanco($tenantB, $user));
        self::assertSame(['dashboard.densidade' => 'confortavel'], $this->noBanco($tenantA, $user));
    }
}
