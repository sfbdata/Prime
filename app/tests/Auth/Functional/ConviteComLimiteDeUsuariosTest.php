<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ConviteController;
use App\Auth\Controller\GerenciarConvitesController;
use App\Auth\Service\VagaNoEscritorio;
use App\Auth\UseCase\AceitarConviteEscritorioComContaUseCase;
use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Limite de usuários no convite do escritório (spec docs/specs/perfis-1.2-planos-e-limites.md P8): o
 * escritório no Free (3 usuários) já com 3 pessoas só é barrado com o bloqueio de envio ligado, que nasce
 * desligado (decisão do dono, 19/10/2026). Vale no envio e no ACEITE (§6, acerto com a f6): o convite pode
 * ter saído com vaga e o escritório ter enchido depois.
 */
#[CoversClass(GerenciarConvitesController::class)]
#[CoversClass(CriarConviteEscritorioUseCase::class)]
#[CoversClass(ConviteController::class)]
#[CoversClass(AceitarConviteEscritorioComContaUseCase::class)]
#[CoversClass(VagaNoEscritorio::class)]
final class ConviteComLimiteDeUsuariosTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;
    private TenantRole $perfil;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // D5 revogada: os planos nascem sem limite de usuários; estes testes precisam de planos com limite.
        $this->em->getConnection()->executeStatement("UPDATE contribua_plano SET limite_usuarios = CASE codigo WHEN 'free' THEN 3 WHEN 'essencial' THEN 8 WHEN 'profissional' THEN 15 ELSE NULL END");
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

        $this->tenant = (new Tenant())->setName('Escritório Cheio ' . uniqid());
        $this->em->persist($this->tenant);
        $this->perfil = $this->perfilQueConvida();
        $this->admin = $this->membro('Admin Convites');
        $this->membro('Segunda Pessoa');
        $this->membro('Terceira Pessoa');
    }

    #[TestDox('bloqueio ligado e Free cheio (3 de 3): o convite é recusado com a mensagem e não é criado')]
    public function testRecusaComBloqueioLigado(): void
    {
        $this->ligarBloqueio(true);

        $email = $this->convidar();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertSame(
            ['Este escritório chegou ao limite de 3 usuários. Para convidar mais pessoas, amplie o plano ou fale com o suporte do BlueJus.'],
            $this->client->getRequest()->getSession()->getFlashBag()->peek('erro'),
        );
        self::assertNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('bloqueio desligado (padrão do deploy): o mesmo escritório cheio convida normalmente')]
    public function testAceitaComBloqueioDesligado(): void
    {
        $this->ligarBloqueio(false);

        $email = $this->convidar();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertNotNull($this->em->getRepository(Invitation::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('aceite: convite pendente de quando havia vaga; com o bloqueio ligado e o escritório cheio, o aceite é recusado')]
    public function testAceiteRecusadoComBloqueioLigado(): void
    {
        [$convidado, $token] = $this->convitePendente();
        $this->ligarBloqueio(true);

        $this->aceitar($convidado, $token);

        self::assertResponseRedirects('/convite/' . $token);
        self::assertSame([sprintf(VagaNoEscritorio::RECUSA, 3)], $this->client->getRequest()->getSession()->getFlashBag()->peek('erro'));
        self::assertNull($this->em->getRepository(UserTenant::class)->findOneBy(['user' => $convidado, 'tenant' => $this->tenant]));
    }

    #[TestDox('aceite: com o bloqueio desligado, o mesmo convite é aceito e a pessoa entra no escritório')]
    public function testAceiteComBloqueioDesligado(): void
    {
        [$convidado, $token] = $this->convitePendente();
        $this->ligarBloqueio(false);

        $this->aceitar($convidado, $token);

        self::assertResponseRedirects();
        self::assertNotNull($this->em->getRepository(UserTenant::class)->findOneBy(['user' => $convidado, 'tenant' => $this->tenant]));
    }

    /** @return array{User, string} */
    private function convitePendente(): array
    {
        $convidado = UserFactory::createOne(['fullName' => 'Quarta Pessoa', 'roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $token = bin2hex(random_bytes(16));
        $convite = new Invitation(email: (string) $convidado->getEmail(), tokenHash: Invitation::hashDoToken($token), type: 'office', expiresAt: new \DateTimeImmutable('+7 days'));
        $convite->setTenant($this->tenant);
        $convite->setTenantRole($this->perfil);
        $convite->setCreatedBy($this->admin);
        $this->em->persist($convite);
        $this->em->flush();

        return [$convidado, $token];
    }

    private function aceitar(User $convidado, string $token): void
    {
        // Quem já tem conta trabalha em outro escritório: entra por ele e aceita o convite deste.
        $outro = (new Tenant())->setName('Escritório de Origem ' . uniqid());
        $this->em->persist($outro);
        $this->em->persist(new UserTenant($convidado, $outro));
        $this->em->flush();
        $this->logarComTenant($this->client, $convidado, $outro);
        $this->client->request('POST', '/convite/' . $token . '/aceitar', ['_token' => 'TOKEN_convite_' . $token]);
    }

    private function convidar(): string
    {
        $this->logarComTenant($this->client, $this->admin, $this->tenant);
        $email = 'quarta_' . uniqid() . '@example.com';
        $this->client->request('POST', '/escritorio/convites/novo', [
            'email' => $email, 'full_name' => 'Quarta Pessoa', 'tenant_role_id' => $this->perfil->getId(), '_token' => 'TOKEN_convite_novo',
        ]);

        return $email;
    }

    private function ligarBloqueio(bool $ligado): void
    {
        $repositorio = static::getContainer()->get(ConfiguracaoDoEspacoRepository::class);
        $configuracao = $repositorio->atual();
        $configuracao->ligarBloqueio($ligado);
        $repositorio->salvar($configuracao, flush: true);
    }

    private function perfilQueConvida(): TenantRole
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.invite']);
        if ($permissao === null) {
            $permissao = (new Permission())->setCode('admin.users.invite')->setDescription('Convidar usuários')->setGroup('admin');
            $this->em->persist($permissao);
        }
        $perfil = (new TenantRole())->setTenant($this->tenant)->setName('Gestor ' . uniqid());
        $this->em->persist($perfil);
        $vinculo = (new TenantRolePermission())->setTenantRole($perfil)->setPermission($permissao);
        $this->em->persist($vinculo);
        $perfil->getTenantRolePermissions()->add($vinculo);
        $this->em->flush();

        return $perfil;
    }

    private function membro(string $nome): User
    {
        $user = UserFactory::createOne(['fullName' => $nome, 'roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $this->em->persist((new UserTenant($user, $this->tenant))->setTenantRole($this->perfil));
        $this->em->flush();

        return $user;
    }
}
