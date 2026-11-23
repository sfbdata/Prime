<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Auth\Controller\EntradaController;
use App\Controller\HomeController;
use App\Controller\Tenant\TenantSelecaoController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\PaginaInicialDoEscritorio;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A tela inicial depois do véu, do `/inicio` e da escolha do escritório (spec multiproduto, §5, item 4): o escritório
 * só Voto vai ao Voto; o completo (todos os de hoje) continua no Expediente, a mesma rota fixa de antes.
 */
#[CoversClass(PaginaInicialDoEscritorio::class)]
#[CoversClass(HomeController::class)]
#[CoversClass(EntradaController::class)]
#[CoversClass(TenantSelecaoController::class)]
final class PaginaInicialPorProdutoTest extends JusPrimeWebTestCase
{
    #[TestDox('/inicio: só Voto vai a /voto; completo segue em /expediente')]
    public function testInicio(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->escritorio([Produto::Voto]);
        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/inicio');
        self::assertResponseRedirects('/voto');

        [$outro, $completo] = $this->escritorio([]);
        $this->logarComTenant($client, $outro, $completo);
        $client->request('GET', '/inicio');
        self::assertResponseRedirects('/expediente');
    }

    #[TestDox('Véu sem destino (ou com destino recusado): só Voto vai a /voto; completo segue em /expediente')]
    public function testVeuSemDestino(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->escritorio([Produto::Voto]);
        $this->logarComTenant($client, $user, $tenant);
        self::assertSame('"\/voto"', $client->request('GET', '/login/entrando')->filter('[data-destino]')->attr('data-destino'));
        self::assertSame('"\/voto"', $client->request('GET', '/login/entrando?para=/notificacoes/count')->filter('[data-destino]')->attr('data-destino'));

        [$outro, $completo] = $this->escritorio([]);
        $this->logarComTenant($client, $outro, $completo);
        self::assertSame('"\/expediente"', $client->request('GET', '/login/entrando')->filter('[data-destino]')->attr('data-destino'));
    }

    #[TestDox('Seleção com um escritório só: só Voto vai a /voto; completo (com `completo` ativa) segue em /expediente')]
    public function testSelecaoComUmEscritorio(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->escritorio([Produto::Voto]);
        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/escritorio/selecionar');
        self::assertResponseRedirects('/voto');

        [$outro, $completo] = $this->escritorio([Produto::Completo]);
        $this->logarComTenant($client, $outro, $completo);
        $client->request('GET', '/escritorio/selecionar');
        self::assertResponseRedirects('/expediente');
    }

    #[TestDox('Troca de escritório (POST): vai à tela inicial do escritório escolhido, Voto ou Expediente')]
    public function testTrocaDeEscritorio(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $soVoto] = $this->escritorio([Produto::Voto]);
        $completo = $this->novoTenant();
        $this->em()->persist(new UserTenant($user, $completo));
        $this->em()->flush();
        $this->logarComTenant($client, $user, $completo);

        $client->request('POST', '/escritorio/selecionar', ['tenant_id' => $soVoto->getId(), '_csrf_token' => 'TOKEN_tenant_selecionar']);
        self::assertResponseRedirects('/voto');

        $client->request('POST', '/escritorio/selecionar', ['tenant_id' => $completo->getId(), '_csrf_token' => 'TOKEN_tenant_selecionar']);
        self::assertResponseRedirects('/expediente');
    }

    /**
     * @param list<Produto> $produtos ativos; vazio = escritório completo, sem linha
     *
     * @return array{User, Tenant}
     */
    private function escritorio(array $produtos): array
    {
        $tenant = $this->novoTenant();
        foreach ($produtos as $produto) {
            $this->em()->persist(Contratacao::ativar($tenant, $produto, OrigemDaContratacao::Cadastro, null, new \DateTimeImmutable('-1 day')));
        }
        $user = (new User())->setEmail('inicial_' . uniqid() . '@adv.com')->setFullName('Ana Inicial')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function novoTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Inicial ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function instalarCsrfStorage(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
