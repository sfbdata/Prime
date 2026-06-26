<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\PontoController;
use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Ponto\JustificativaPonto;
use App\Entity\Ponto\RegistroPonto;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Isolamento multi-tenant do Ponto via HTTP (modelo POR VÍNCULO). O vetor é o EMPREGADO
 * COMPARTILHADO (vínculo ativo em A e B): os guards pré-existentes (existeVinculoAtivo do
 * usuário-alvo + posse) já bloqueiam o usuário de tenant único, então é o TenantFilter — keyed
 * pelo escritório ATIVO (sessão) — que fecha o resíduo. Cada teste tem controle positivo
 * (escritório certo → sucesso) + negativo (escritório errado → 404). em->clear() força SQL real.
 */
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class PontoIsolamentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Editar a própria justificativa fora do escritório onde foi criada retorna 404 (self, per-vínculo)')]
    public function testEditarJustificativaSelfIsolaPorTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user    = $this->criarUsuario('compartilhado_' . uniqid() . '@test.com');
        $this->vincular($user, $tenantA);
        $this->vincular($user, $tenantB);
        $justB = $this->criarJustificativa($user, $tenantB, 'pendente');
        $id    = (int) $justB->getId();

        $this->instalarCsrfStorage();

        // controle positivo: no escritório B (onde a justificativa vive) edita normalmente
        $this->logarComTenant($client, $user, $tenantB);
        $this->limpar();
        $client->request('POST', "/ponto/justificativa/{$id}/editar", [
            '_token' => $this->gerarCsrf('editar_justificativa_' . $id),
        ]);
        self::assertResponseStatusCodeSame(302);

        // negativo: logado no escritório A, a justificativa de B some pelo filtro (ParamConverter) → 404
        $this->logarComTenant($client, $user, $tenantA);
        $this->limpar();
        $client->request('POST', "/ponto/justificativa/{$id}/editar", [
            '_token' => $this->gerarCsrf('editar_justificativa_' . $id),
        ]);
        self::assertResponseStatusCodeSame(404, 'justificativa de outro escritório não pode ser editada');
    }

    #[TestDox('Admin do escritório A não edita batida do escritório B do mesmo empregado (404 pelo filtro)')]
    public function testPontoEditAdminIsolaPorTenant(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $alvo     = $this->criarUsuario('alvo_' . uniqid() . '@test.com');
        $this->vincular($alvo, $tenantA);
        $this->vincular($alvo, $tenantB);
        $adminA = $this->criarUsuario('admin_a_' . uniqid() . '@test.com');
        $this->vincular($adminA, $tenantA);
        $adminB = $this->criarUsuario('admin_b_' . uniqid() . '@test.com');
        $this->vincular($adminB, $tenantB);

        $regB = $this->criarRegistro($alvo, $tenantB, '2026-03-10 09:00:00');
        $idReg = (int) $regB->getId();
        $idAlvo = (int) $alvo->getId();

        // controle positivo: admin de B vê o formulário de edição da batida de B
        $this->logarComTenant($client, $adminB, $tenantB);
        $this->limpar();
        $client->request('GET', "/tenant/{$tenantB->getId()}/user/{$idAlvo}/ponto/{$idReg}/edit");
        self::assertResponseIsSuccessful();

        // negativo: admin de A (vínculo do alvo em A passa o guard) — a batida de B some pelo filtro → 404
        $this->logarComTenant($client, $adminA, $tenantA);
        $this->limpar();
        $client->request('GET', "/tenant/{$tenantA->getId()}/user/{$idAlvo}/ponto/{$idReg}/edit");
        self::assertResponseStatusCodeSame(404, 'batida de outro escritório não pode ser acessada');
    }

    #[TestDox('Admin do escritório A não abona justificativa do escritório B do mesmo empregado (404 pelo filtro)')]
    public function testAprovarJustificativaAdminIsolaPorTenant(): void
    {
        $client   = static::createClient();
        // o CSRF storage fake precisa sobreviver às duas requests (a rota checa CSRF antes do find);
        // sem disableReboot o kernel reinicia e descarta o storage, derrubando o controle positivo.
        $client->disableReboot();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $alvo     = $this->criarUsuario('alvo_' . uniqid() . '@test.com');
        $this->vincular($alvo, $tenantA);
        $this->vincular($alvo, $tenantB);
        $adminA = $this->criarUsuario('admin_a_' . uniqid() . '@test.com');
        $this->vincular($adminA, $tenantA);
        $adminB = $this->criarUsuario('admin_b_' . uniqid() . '@test.com');
        $this->vincular($adminB, $tenantB);

        $justB = $this->criarJustificativa($alvo, $tenantB, 'pendente');
        $idJust = (int) $justB->getId();
        $idAlvo = (int) $alvo->getId();

        $this->instalarCsrfStorage();

        // negativo: admin de A tenta abonar a justificativa de B → 404 pelo filtro
        $this->logarComTenant($client, $adminA, $tenantA);
        $this->limpar();
        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$idAlvo}/justificativa/{$idJust}/aprovar", [
            '_token' => $this->gerarCsrf('justificativa_aprovar_' . $idJust),
        ]);
        self::assertResponseStatusCodeSame(404, 'justificativa de outro escritório não pode ser abonada');

        // controle positivo: admin de B abona a justificativa de B
        $this->logarComTenant($client, $adminB, $tenantB);
        $this->limpar();
        $client->request('POST', "/tenant/{$tenantB->getId()}/user/{$idAlvo}/justificativa/{$idJust}/aprovar", [
            '_token' => $this->gerarCsrf('justificativa_aprovar_' . $idJust),
        ]);
        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertSame('abonado', $em->find(JustificativaPonto::class, $idJust)?->getStatus());
    }

    // ----------------------------------------------------------------- helpers

    private function limpar(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PONTO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(string $email): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Cria um vínculo ativo (role isSystem → bypassa o PermissionChecker, isolando o filtro). */
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

    private function criarRegistro(User $user, Tenant $tenant, string $dataHora): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();

        return $registro;
    }

    private function criarJustificativa(User $user, Tenant $tenant, string $status): JustificativaPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $justificativa = new JustificativaPonto();
        $justificativa->setUser($user);
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus($status);
        $em->persist($justificativa);
        $em->flush();

        return $justificativa;
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

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }
}
