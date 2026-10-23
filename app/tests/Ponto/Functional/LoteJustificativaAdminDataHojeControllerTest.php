<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Lote de justificativas do administrador (`TenantController::novaJustificativaAdmin`) e a data
 * de HOJE. A data lida do formulário carrega a hora corrente; comparada com o começo do dia, hoje
 * passava por futura e era ignorada. A regra é por DATA: hoje e passado entram, amanhã não.
 */
#[CoversClass(TenantController::class)]
final class LoteJustificativaAdminDataHojeControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('lote com a data de hoje cria a justificativa e a batida esquecida')]
    public function testDataDeHojeCriaBatida(): void
    {
        [$client, $tenant, $user] = $this->adminLogado();
        $hoje = new \DateTimeImmutable('today');

        $this->enviarLote($client, $tenant, $user, [$hoje]);

        self::assertResponseRedirects();
        self::assertSame([$hoje->format('Y-m-d')], $this->datasDasJustificativasDo($user));
        self::assertSame([$hoje->format('Y-m-d') . ' 00:00'], $this->batidasDo($user));
    }

    #[TestDox('lote com a data de amanhã continua ignorado')]
    public function testDataDeAmanhaEhIgnorada(): void
    {
        [$client, $tenant, $user] = $this->adminLogado();

        $this->enviarLote($client, $tenant, $user, [new \DateTimeImmutable('tomorrow')]);

        self::assertResponseRedirects();
        self::assertSame([], $this->datasDasJustificativasDo($user));
        self::assertSame([], $this->batidasDo($user));
    }

    #[TestDox('lote com ontem, hoje e amanhã cria só ontem e hoje')]
    public function testLoteMistoAceitaPassadoEHoje(): void
    {
        [$client, $tenant, $user] = $this->adminLogado();
        $ontem = new \DateTimeImmutable('yesterday');
        $hoje  = new \DateTimeImmutable('today');

        $this->enviarLote($client, $tenant, $user, [$ontem, $hoje, new \DateTimeImmutable('tomorrow')]);

        self::assertResponseRedirects();
        self::assertSame(
            [$ontem->format('Y-m-d'), $hoje->format('Y-m-d')],
            $this->datasDasJustificativasDo($user),
        );
        self::assertSame(
            [$ontem->format('Y-m-d') . ' 00:00', $hoje->format('Y-m-d') . ' 00:00'],
            $this->batidasDo($user),
        );
    }

    /**
     * @return array{KernelBrowser, Tenant, User}
     */
    private function adminLogado(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $user   = $this->criarUsuario();
        $this->vincular($admin, $tenant);
        $this->vincular($user, $tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $admin, $tenant);

        return [$client, $tenant, $user];
    }

    /**
     * Esquecimento de registro à meia-noite: a hora nunca está no futuro, o que isola a regra da data.
     *
     * @param list<\DateTimeImmutable> $dias
     */
    private function enviarLote(KernelBrowser $client, Tenant $tenant, User $user, array $dias): void
    {
        $client->request(
            'POST',
            sprintf('/tenant/%d/user/%d/justificativa/nova', $tenant->getId(), $user->getId()),
            [
                '_token'                => 'TOKEN_admin_nova_justificativa_' . $user->getId(),
                'tipo'                  => 'esquecimento_registro',
                'datas'                 => implode(',', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dias)),
                'tipoRegistroEsquecido' => 'entrada',
                'horaRegistroEsquecido' => '00:00',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function datasDasJustificativasDo(User $user): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $justificativas = $em->getRepository(JustificativaPonto::class)->findBy(['user' => $user->getId()], ['data' => 'ASC']);

        return array_map(static fn (JustificativaPonto $j): string => $j->getData()->format('Y-m-d'), $justificativas);
    }

    /**
     * @return list<string>
     */
    private function batidasDo(User $user): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $registros = $em->getRepository(RegistroPonto::class)->findBy(['user' => $user->getId()], ['dataHora' => 'ASC']);

        return array_map(static fn (RegistroPonto $r): string => $r->getDataHora()->format('Y-m-d H:i'), $registros);
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant LOTE HOJE ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * @param list<string> $papeis
     */
    private function criarUsuario(array $papeis = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('lote_hoje_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
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
}
