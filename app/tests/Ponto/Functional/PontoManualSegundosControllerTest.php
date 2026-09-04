<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O lançamento manual de ponto ignorava os segundos digitados: a feature flag
 * `deveMostrarSegundosBatida()` (removida) só liberava `with_seconds` para um único e-mail de
 * teste, e mesmo nesse caso `pontoAdd()` não repassava a opção ao form. Estes testes provam que
 * os segundos são aceitos e persistidos para QUALQUER usuário, nos dois fluxos.
 */
#[CoversClass(TenantController::class)]
final class PontoManualSegundosControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('pontoAdd com hora contendo segundos grava a batida com os segundos exatos')]
    public function testAddComSegundosPreservaSegundos(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/add", [
            'registro_ponto_manual' => ['data' => '2026-03-10', 'hora' => '08:15:37', 'tipo' => 'entrada'],
            'competencia'           => '2026-03',
            '_token'                => 'TOKEN_ponto_manual_add',
        ]);

        self::assertResponseRedirects();
        $registro = $this->recarregarUnicaBatida($alvo);
        self::assertSame('08:15:37', $registro->getDataHora()?->format('H:i:s'), 'os segundos digitados deveriam ter sido preservados');
    }

    #[TestDox('pontoEdit com hora contendo segundos atualiza a batida com os segundos exatos')]
    public function testEditComSegundosPreservaSegundos(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);
        $registro   = $this->criarBatida($alvo, $tenant, '2026-03-10 08:00:00');
        $registroId = (int) $registro->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/{$registroId}/edit", [
            'registro_ponto_manual' => ['data' => '2026-03-10', 'hora' => '14:42:09', 'tipo' => 'saida'],
            'competencia'           => '2026-03',
            '_token'                => 'TOKEN_ponto_manual_edit',
        ]);

        self::assertResponseRedirects();
        self::assertSame('14:42:09', $this->recarregarBatida($registroId)->getDataHora()?->format('H:i:s'), 'os segundos digitados deveriam ter sido preservados na edição');
    }

    // ----------------------------------------------------------------- helpers

    private function recarregarUnicaBatida(User $user): RegistroPonto
    {
        $registros = $this->emSemFiltro()->getRepository(RegistroPonto::class)->findBy(['user' => $user->getId()]);
        self::assertCount(1, $registros, 'deveria existir exatamente uma batida criada');

        return $registros[0];
    }

    private function recarregarBatida(int $id): RegistroPonto
    {
        $registro = $this->emSemFiltro()->find(RegistroPonto::class, $id);
        self::assertInstanceOf(RegistroPonto::class, $registro);

        return $registro;
    }

    private function emSemFiltro(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO SEGUNDOS ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Ponto Segundos');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Ponto Segundos');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarBatida(User $user, Tenant $tenant, string $dataHora): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $em->persist($registro);
        $em->flush();

        return $registro;
    }
}
