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
final class CriarTarefaControllerTest extends JusPrimeWebTestCase
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
        $tenant->setName('Tenant Criar ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Cria a pasta onde a meta será criada. O criador da pasta dá acesso ao
     * fluxo de criação (verificação em criarParaPasta).
     */
    private function criarPasta(Tenant $tenant, User $criador): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-NEW-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
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

    /**
     * @return int[] IDs dos responsáveis vinculados à meta recém-criada.
     */
    private function idsResponsaveis(int $tarefaId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tarefa = $em->find(Tarefa::class, $tarefaId);
        self::assertNotNull($tarefa, 'a meta deveria existir no banco');

        return array_map(
            static fn(User $u): int => (int) $u->getId(),
            $tarefa->getResponsaveis()->toArray()
        );
    }

    #[TestDox('Cria meta com dois responsáveis: ambos ficam vinculados')]
    public function testCriaComMultiplosResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $resp1  = $this->criarUsuario($tenant, 'resp1_' . uniqid() . '@test.com');
        $resp2  = $this->criarUsuario($tenant, 'resp2_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta conjunta',
            'descricao'    => 'Executada por duas pessoas',
            'responsaveis' => [(int) $resp1->getId(), (int) $resp2->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        $ids = $this->idsResponsaveis((int) $data['id']);
        self::assertCount(2, $ids);
        self::assertContains((int) $resp1->getId(), $ids);
        self::assertContains((int) $resp2->getId(), $ids);
    }

    #[TestDox('Ignora responsável de outro tenant enviado no array (filtro por tenant)')]
    public function testIgnoraResponsavelDeOutroTenant(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autor    = $this->criarUsuario($tenantA, 'autor_' . uniqid() . '@test.com');
        $respA    = $this->criarUsuario($tenantA, 'respA_' . uniqid() . '@test.com');
        $estranho = $this->criarUsuario($tenantB, 'estranho_' . uniqid() . '@test.com');
        $pasta    = $this->criarPasta($tenantA, $autor);
        $pastaId  = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenantA);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'       => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'       => 'Meta com intruso',
            'descricao'    => 'Tenta vincular alguém de outro tenant',
            'responsaveis' => [(int) $respA->getId(), (int) $estranho->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        $ids = $this->idsResponsaveis((int) $data['id']);
        self::assertSame([(int) $respA->getId()], $ids, 'apenas o responsável do tenant atual deve ser vinculado');
    }

    #[TestDox('Cria meta sem responsáveis: permitido, fica com coleção vazia')]
    public function testCriaSemResponsaveis(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => 'Meta sem responsável',
            'descricao' => 'Ninguém atribuído ainda',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['sucesso'] ?? false);

        self::assertSame([], $this->idsResponsaveis((int) $data['id']));
    }

    #[TestDox('Título vazio retorna 422 e não cria meta')]
    public function testTituloVazioRetorna422(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => $this->gerarCsrf('tarefa_criar_' . $pastaId),
            'titulo'    => '',
            'descricao' => 'Sem título',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('CSRF inválido retorna 403 e não cria meta')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $pasta  = $this->criarPasta($tenant, $autor);
        $pastaId = (int) $pasta->getId();

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/pasta/{$pastaId}/criar", [
            '_token'    => 'token_invalido',
            'titulo'    => 'Meta',
            'descricao' => 'Descrição',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
