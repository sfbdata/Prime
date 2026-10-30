<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Código da conta e data de abertura em /master/dados/contas (desenho 05 - Perfis 1.2 - VS 30.10.26, L173): cada
 * conta leva `codigo` (BJ-XXXX-XXXX-XXXX) e `criadoEm` ISO. A conta sem código ganha um na listagem e o mesmo vale na
 * seguinte (idempotente). Só o Master recebe: o primário de outra conta toma 403 e o corpo não leva nenhum código.
 */
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(ConsultarContasUseCase::class)]
final class CodigoDaContaNoPainelTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $master;
    private Tenant $contaDoMaster;
    private User $primario;
    private Tenant $alvo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->master = UserFactory::createOne(['fullName' => 'Farlei Master', 'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'isActive' => true])->_real();
        $this->contaDoMaster = $this->conta('Escritório do Master', $this->master);
        $this->vincular($this->master, $this->contaDoMaster);

        $this->primario = UserFactory::createOne(['fullName' => 'Ana Primária', 'roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $this->alvo = $this->conta('Silva Advogados', $this->primario);
        $this->vincular($this->primario, $this->alvo);
    }

    #[TestDox('conta sem código ganha um BJ-XXXX-XXXX-XXXX na listagem, gravado no Tenant e igual na listagem seguinte; criadoEm vem em ISO')]
    public function testGaranteECodigoEstavel(): void
    {
        self::assertNull($this->alvo->getCodigoConta());
        $this->logarComTenant($this->client, $this->master, $this->contaDoMaster);

        $primeira = $this->contaDaLista($this->alvo);
        $this->em->clear();
        $segunda = $this->contaDaLista($this->alvo);

        self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, $primeira['codigo']);
        self::assertSame($primeira['codigo'], $segunda['codigo'], 'idempotente: não gera outro');
        self::assertSame($primeira['codigo'], $this->em->getRepository(Tenant::class)->find($this->alvo->getId())?->getCodigoConta());
        self::assertSame($this->alvo->getCreatedAt()->getTimestamp(), (new \DateTimeImmutable($primeira['criadoEm']))->getTimestamp());
        self::assertSame(str_pad((string) $this->alvo->getId(), 6, '0', \STR_PAD_LEFT), $primeira['numero']);
    }

    #[TestDox('cada conta tem o seu código: o da conta do Master e o do cliente diferem')]
    public function testCodigosDiferem(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->contaDoMaster);

        $a = $this->contaDaLista($this->alvo)['codigo'];
        $b = $this->contaDaLista($this->contaDoMaster)['codigo'];

        self::assertNotSame($a, $b);
    }

    #[TestDox('o primário de outra conta toma 403 e a resposta não leva o código de nenhum escritório')]
    public function testCodigoNaoVazaForaDoMaster(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->contaDoMaster);
        $codigo = $this->contaDaLista($this->alvo)['codigo'];

        $this->logarComTenant($this->client, $this->primario, $this->alvo);
        $this->client->request('GET', '/master/dados/contas');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString($codigo, (string) $this->client->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function contaDaLista(Tenant $conta): array
    {
        $this->client->request('GET', '/master/dados/contas');
        self::assertResponseIsSuccessful();
        $contas = array_column((array) (json_decode((string) $this->client->getResponse()->getContent(), true)['contas'] ?? []), null, 'id');
        self::assertArrayHasKey($conta->getId(), $contas);

        return $contas[$conta->getId()];
    }

    private function conta(string $nome, User $criador): Tenant
    {
        $conta = (new Tenant())->setName($nome . ' ' . uniqid())->setCriadoPor($criador);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function instalarCsrfFixo(): void
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
}
