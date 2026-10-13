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
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * SEC-013 — `TarefaController::verificarAcessoTarefa` conferia se o criador ou o responsável da
 * PASTA tinha vínculo com o escritório da sessão, não se a META era do escritório da sessão. Quem
 * está em dois escritórios (A e B) passava pelo guarda com a meta de B estando em A, se o
 * TenantFilter falhasse. Agora a posse vem primeiro: meta de outro escritório → 404.
 *
 * O cenário que só esse guarda cobre: filtro DESLIGADO dentro da requisição (senão o 404 é do
 * filtro) e o MESMO usuário — criador da pasta e da meta — vinculado aos dois escritórios (senão
 * o guarda de vínculo, que já existia, recusaria sozinho). O irmão em A prova que o guarda não
 * bloqueia o que é do escritório.
 */
#[CoversClass(TarefaController::class)]
#[Group('tarefa')]
final class TarefaPosseDoEscritorioTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return $this->em()->getConnection();
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Posse Meta ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Usuário com papel de sistema em CADA escritório informado. */
    private function criarUsuarioEm(Tenant ...$tenants): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('posse_meta_' . uniqid() . '@test.com');
        $user->setFullName('Usuário de dois escritórios');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        foreach ($tenants as $tenant) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador ' . uniqid());
            $role->setIsSystem(true);
            $this->em()->persist($role);

            $vinculo = new UserTenant($user, $tenant);
            $vinculo->setTenantRole($role);
            $this->em()->persist($vinculo);
        }
        $this->em()->flush();

        return $user;
    }

    private function criarMeta(Tenant $tenant, User $autor): int
    {
        $pasta = new Pasta();
        $pasta->setNup('POSSE-META-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor);
        $this->em()->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta do escritório');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($autor);
        $this->em()->persist($tarefa);
        $this->em()->flush();

        return (int) $tarefa->getId();
    }

    private function instalarCsrfStorage(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        });
    }

    private function acompanhamentos(int $tarefaId): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM tarefa_acompanhamento WHERE tarefa_id = ?', [$tarefaId]);
    }

    #[TestDox('Acompanhar meta do escritório B com a sessão em A (usuário nos dois, filtro desligado): 404 e nada gravado')]
    public function testAcompanharMetaDeOutroEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUsuarioEm($tenantA, $tenantB);
        $metaB   = $this->criarMeta($tenantB, $usuario);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $tenantA);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$metaB}/acompanhar", ['_token' => 'TOKEN_acompanhar_tarefa_' . $metaB]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->acompanhamentos($metaB), 'a meta de B não ganhou acompanhamento');
    }

    #[TestDox('Excluir meta do escritório B com a sessão em A (criador nos dois, filtro desligado): 404 e a meta continua')]
    public function testExcluirMetaDeOutroEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUsuarioEm($tenantA, $tenantB);
        $metaB   = $this->criarMeta($tenantB, $usuario);
        $antes   = $this->conexao()->fetchAssociative('SELECT titulo, tenant_id FROM tarefa WHERE id = ?', [$metaB]);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $tenantA);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$metaB}/excluir", ['_token' => 'TOKEN_excluir_tarefa_' . $metaB]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertNotFalse($antes);
        self::assertSame($antes, $this->conexao()->fetchAssociative('SELECT titulo, tenant_id FROM tarefa WHERE id = ?', [$metaB]));
    }

    #[TestDox('Irmão: a mesma pessoa, a meta do escritório da sessão — acompanhar funciona')]
    public function testAcompanharMetaDoProprioEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUsuarioEm($tenantA, $tenantB);
        $metaA   = $this->criarMeta($tenantA, $usuario);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $tenantA);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$metaA}/acompanhar", ['_token' => 'TOKEN_acompanhar_tarefa_' . $metaA]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->acompanhamentos($metaA));
    }
}
