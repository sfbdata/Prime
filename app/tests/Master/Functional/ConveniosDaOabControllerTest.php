<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\ConveniosDaOabController;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Painel "Convênio OAB por estado" da seção Contas (desenho 05 VS 25.10.26, P4b): leitura, as três escolhas, a recusa e
 * o acesso só do Master. O armazém de CSRF é fixo (padrão dos testes do painel).
 */
#[CoversClass(ConveniosDaOabController::class)]
final class ConveniosDaOabControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . ConveniosDaOabController::CSRF;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    #[TestDox('Master lê os 27 estados: todos grátis por padrão, com as contas de cada um')]
    public function testLe(): void
    {
        $this->entrarComoMaster();

        $this->client->request('GET', '/master/convenios');

        self::assertResponseIsSuccessful();
        $estados = $this->json()['estados'];
        self::assertCount(27, $estados);
        self::assertSame(['livre'], array_values(array_unique(array_column($estados, 'modo'))));
        self::assertNull($estados[0]['desde']);
    }

    #[TestDox('quem não é Master recebe 403 e nada muda')]
    public function testNaoMaster(): void
    {
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/convenios');
        self::assertResponseStatusCodeSame(403);

        $this->postar(['uf' => 'SP', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '2026-11-01']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('CSRF errado: 403 e nada muda')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();

        $this->postar(['uf' => 'SP', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '2026-11-01'], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->gravada());
    }

    #[TestDox('valor diferenciado, depois sem convênio com motivo, depois grátis (apaga a linha)')]
    public function testAsTresEscolhas(): void
    {
        $master = $this->entrarComoMaster();

        $this->postar(['uf' => 'sp', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '2026-11-01']);
        self::assertResponseIsSuccessful();
        $sp = $this->estado('SP');
        self::assertSame(['dif', 9.9, '2026-11-01'], [$sp['modo'], $sp['valor'], $sp['desde']]);

        $this->postar(['uf' => 'SP', 'modo' => 'sem', 'valor' => 29.9, 'desde' => '2026-12-01', 'motivo' => 'Convênio não renovado']);
        $sp = $this->estado('SP');
        self::assertSame(['sem', 29.9, 'Convênio não renovado'], [$sp['modo'], $sp['valor'], $sp['motivo']]);

        $this->em->clear();
        self::assertSame($master->getId(), $this->gravada()?->getAlteradoPor()?->getId());

        $this->postar(['uf' => 'SP', 'modo' => 'livre']);
        self::assertSame('livre', $this->estado('SP')['modo']);
        self::assertNull($this->gravada());
    }

    #[TestDox('pedido recusado: 422 com a mensagem, e nada grava')]
    public function testRecusa(): void
    {
        $this->entrarComoMaster();

        foreach ([
            ['uf' => 'XX', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '2026-11-01'],
            ['uf' => 'SP', 'modo' => 'estranho', 'valor' => '9,90', 'desde' => '2026-11-01'],
            ['uf' => 'SP', 'modo' => 'dif', 'valor' => 'abc', 'desde' => '2026-11-01'],
            ['uf' => 'SP', 'modo' => 'dif', 'valor' => '-1', 'desde' => '2026-11-01'],
            ['uf' => 'SP', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '01/11/2026'],
            ['uf' => 'SP', 'modo' => 'sem', 'valor' => '9,90', 'desde' => '2026-02-31'],
        ] as $corpo) {
            $this->postar($corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('erro', $this->json());
        }
        self::assertNull($this->gravada());
    }

    #[TestDox('a lista de contas traz a UF e o convênio de cada conta')]
    public function testContasTrazemOConvenio(): void
    {
        $this->entrarComoMaster();
        $tenant = (new Tenant())->setName('Conta do convênio ' . uniqid())->setEstado('SP');
        $this->em->persist($tenant);
        $this->em->flush();
        $this->postar(['uf' => 'SP', 'modo' => 'dif', 'valor' => '9,90', 'desde' => '2026-11-01']);

        $this->client->request('GET', '/master/dados/contas');

        self::assertResponseIsSuccessful();
        $contas = array_column($this->json()['contas'], null, 'id');
        self::assertSame('SP', $contas[$tenant->getId()]['uf']);
        self::assertSame(['modo' => 'dif', 'valor' => 9.9], $contas[$tenant->getId()]['convenio']);
    }

    /** @return array<string, mixed> */
    private function estado(string $uf): array
    {
        return array_column($this->json()['estados'], null, 'uf')[$uf];
    }

    private function gravada(string $uf = 'SP'): ?ConvenioDaUf
    {
        return static::getContainer()->get(ConvenioDaUfRepository::class)->buscar($uf);
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório dos convênios ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('convenio_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário dos Convênios');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', '/master/convenios', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }
}
