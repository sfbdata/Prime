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
 * A2 — lançamento/edição MANUAL de ponto (domínio ALTO) passa a exigir token CSRF por-intenção
 * stateful, validado explicitamente no controller ('ponto_manual_add'/'ponto_manual_edit'; o form
 * tem csrf_protection=>false). Sem token → 403, sem gravar; com token válido → grava. Antes do A2
 * esses dois POSTs não tinham CSRF NEM cobertura functional.
 */
#[CoversClass(TenantController::class)]
final class PontoManualCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('pontoAdd POST sem token CSRF retorna 403 e não cria batida')]
    public function testAddSemTokenRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/add", $this->payloadBatida());

        self::assertResponseStatusCodeSame(403, 'add de batida sem CSRF deveria ser barrado');
        self::assertSame(0, $this->contarBatidas($alvo), 'nenhuma batida pode ter sido criada sem CSRF');
    }

    #[TestDox('pontoAdd POST com token CSRF válido cria a batida')]
    public function testAddComTokenCriaBatida(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/add", $this->payloadBatida('TOKEN_ponto_manual_add'));

        self::assertResponseRedirects();
        self::assertSame(1, $this->contarBatidas($alvo), 'a batida deveria ter sido criada com CSRF válido');
    }

    #[TestDox('pontoEdit POST sem token CSRF retorna 403 e não altera a batida')]
    public function testEditSemTokenRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);
        $registro   = $this->criarBatida($alvo, $tenant, '2026-03-10 08:00:00');
        $registroId = (int) $registro->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/{$registroId}/edit", $this->payloadBatida(null, 'saida'));

        self::assertResponseStatusCodeSame(403, 'edit de batida sem CSRF deveria ser barrado');
        self::assertSame(RegistroPonto::TIPO_ENTRADA, $this->recarregarBatida($registroId)->getTipo(), 'a batida não pode ter sido alterada sem CSRF');
    }

    #[TestDox('pontoEdit POST com token CSRF válido atualiza a batida')]
    public function testEditComTokenAtualizaBatida(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);
        $registro   = $this->criarBatida($alvo, $tenant, '2026-03-10 08:00:00');
        $registroId = (int) $registro->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/ponto/{$registroId}/edit", $this->payloadBatida('TOKEN_ponto_manual_edit', 'saida'));

        self::assertResponseRedirects();
        self::assertSame(RegistroPonto::TIPO_SAIDA, $this->recarregarBatida($registroId)->getTipo(), 'a batida deveria ter sido atualizada com CSRF válido');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function payloadBatida(?string $token = null, string $tipo = 'entrada'): array
    {
        $payload = [
            'registro_ponto_manual' => ['data' => '2026-03-10', 'hora' => '08:00', 'tipo' => $tipo],
            'competencia'           => '2026-03',
        ];
        if ($token !== null) {
            $payload['_token'] = $token;
        }

        return $payload;
    }

    private function contarBatidas(User $user): int
    {
        return count($this->emSemFiltro()->getRepository(RegistroPonto::class)->findBy(['user' => $user->getId()]));
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
        $tenant->setName('Tenant PONTO MANUAL ' . uniqid());
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
        $user->setFullName('Admin Ponto Manual');
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
        $user->setFullName('Alvo Ponto Manual');
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
