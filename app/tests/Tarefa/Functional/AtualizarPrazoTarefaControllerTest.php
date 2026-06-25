<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TarefaController::class)]
final class AtualizarPrazoTarefaControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Usuário ' . uniqid());
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Cria uma meta. A pasta tem $autorPasta como criador (garante acesso via
     * verificarAcessoTarefa); a meta tem $criadorMeta como criador (regra de prazo).
     */
    private function criarTarefa(Tenant $tenant, User $autorPasta, User $criadorMeta, ?\DateTimeImmutable $prazo = null): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-PRZ-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autorPasta);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta com prazo');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criadorMeta);
        $tarefa->setPrazo($prazo);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
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

    #[TestDox('Criador altera o prazo: redireciona e persiste o novo prazo')]
    public function testCriadorAlteraPrazo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor);
        $id     = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/prazo", [
            '_token' => $this->gerarCsrf('atualizar_prazo_tarefa_' . $id),
            'prazo'  => '2026-12-31',
        ]);

        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Tarefa::class, $id);
        self::assertSame('2026-12-31', $recarregada->getPrazo()?->format('Y-m-d'));
    }

    #[TestDox('Criador limpa o prazo (campo vazio): prazo fica nulo')]
    public function testCriadorLimpaPrazo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor, new \DateTimeImmutable('2026-01-01'));
        $id     = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/prazo", [
            '_token' => $this->gerarCsrf('atualizar_prazo_tarefa_' . $id),
            'prazo'  => '',
        ]);

        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Tarefa::class, $id);
        self::assertNull($recarregada->getPrazo());
    }

    #[TestDox('Não-criador (mesmo tenant) não altera o prazo: permanece inalterado')]
    public function testNaoCriadorNaoAltera(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $outro  = $this->criarUsuario($tenant, 'outro_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor, new \DateTimeImmutable('2026-01-01'));
        $id     = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/tarefas/{$id}/prazo", [
            '_token' => $this->gerarCsrf('atualizar_prazo_tarefa_' . $id),
            'prazo'  => '2026-12-31',
        ]);

        // UseCase lança PrazoNaoEditavelException → controller faz flash + redirect.
        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Tarefa::class, $id);
        self::assertSame('2026-01-01', $recarregada->getPrazo()?->format('Y-m-d'), 'prazo de terceiro não pode mudar');
    }

    #[TestDox('Usuário do tenant B não altera prazo de meta do tenant A (IDOR cross-tenant)')]
    public function testCrossTenantNaoAltera(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autorA   = $this->criarUsuario($tenantA, 'autor_a_' . uniqid() . '@test.com');
        $atacante = $this->criarUsuario($tenantB, 'atacante_b_' . uniqid() . '@test.com');
        $tarefa   = $this->criarTarefa($tenantA, $autorA, $autorA, new \DateTimeImmutable('2026-01-01'));
        $id       = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $atacante, $tenantB);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear(); // identity map vazia, como em produção por request → ParamConverter consulta o banco com o filtro

        $client->request('POST', "/tarefas/{$id}/prazo", [
            '_token' => $this->gerarCsrf('atualizar_prazo_tarefa_' . $id),
            'prazo'  => '2026-12-31',
        ]);

        // Com o filtro de tenant, o find() cross-tenant retorna null → 404 antes do guard
        self::assertResponseStatusCodeSame(404);

        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        $recarregada = $em->find(Tarefa::class, $id);
        self::assertSame('2026-01-01', $recarregada->getPrazo()?->format('Y-m-d'), 'meta do tenant A deve permanecer intacta');
    }

    #[TestDox('CSRF inválido retorna 403 mesmo sendo o criador')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor, new \DateTimeImmutable('2026-01-01'));
        $id     = (int) $tarefa->getId();

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/prazo", [
            '_token' => 'token_invalido',
            'prazo'  => '2026-12-31',
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $recarregada = $em->find(Tarefa::class, $id);
        self::assertSame('2026-01-01', $recarregada->getPrazo()?->format('Y-m-d'));
    }
}
