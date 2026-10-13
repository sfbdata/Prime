<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\MetaNaListaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * SEC-013b — `MetaNaListaController::verificarAcessoTarefa` era cópia do guarda antigo do
 * TarefaController: conferia o vínculo do criador/responsável da PASTA com o escritório da
 * sessão, não a posse da META. Mesma correção do SEC-013 (8273810f): meta de outro escritório → 404.
 *
 * Mesmo cenário do TarefaPosseDoEscritorioTest: filtro DESLIGADO na requisição (senão o 404 é do
 * filtro) e o MESMO usuário — criador da pasta e da meta — nos dois escritórios (senão o guarda de
 * vínculo recusaria sozinho). Os UseCases têm segunda trava (LogicException → 500); o teste exige
 * 404, que só o guarda do controller dá. O irmão em A prova que o guarda não bloqueia o que é do
 * escritório.
 */
#[CoversClass(MetaNaListaController::class)]
#[Group('tarefa')]
final class MetaNaListaPosseDoEscritorioTest extends JusPrimeWebTestCase
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
        $tenant->setName('Tenant Posse Lista ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Usuário com papel de sistema em CADA escritório informado. */
    private function criarUsuarioEm(Tenant ...$tenants): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('posse_lista_' . uniqid() . '@test.com');
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

    private function criarMeta(Tenant $tenant, User $autor, string $status): int
    {
        $pasta = new Pasta();
        $pasta->setNup('POSSE-LISTA-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor);
        $this->em()->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta do escritório');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($autor);
        $tarefa->setStatus($status);
        $tarefa->addResponsavel($autor);
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

    /** @return array<string, mixed>|false */
    private function fotografar(int $tarefaId): array|false
    {
        return $this->conexao()->fetchAssociative('SELECT titulo, status, tenant_id FROM tarefa WHERE id = ?', [$tarefaId]);
    }

    /** @return iterable<string, array{0: string, 1: string}> ação => [rota, status inicial da meta] */
    public static function acoes(): iterable
    {
        yield 'renomear' => ['renomear', Tarefa::STATUS_PENDENTE];
        yield 'reabrir'  => ['reabrir', Tarefa::STATUS_CONCLUIDA];
        yield 'alertar'  => ['alertar', Tarefa::STATUS_PENDENTE];
    }

    #[DataProvider('acoes')]
    #[TestDox('$acao meta do escritório B com a sessão em A (usuário nos dois, filtro desligado): 404 e nada muda')]
    public function testAcaoEmMetaDeOutroEscritorio(string $acao, string $status): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUsuarioEm($tenantA, $tenantB);
        $metaB   = $this->criarMeta($tenantB, $usuario, $status);
        $antes   = $this->fotografar($metaB);
        $alertas = (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM notificacao');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $tenantA);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$metaB}/{$acao}", [
            '_token'  => "TOKEN_{$acao}_tarefa_{$metaB}",
            'titulo'  => 'Nome trocado de fora',
            'usuario' => (string) $usuario->getId(),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertNotFalse($antes);
        self::assertSame($antes, $this->fotografar($metaB), 'a meta de B não mudou');
        self::assertSame($alertas, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM notificacao'), 'nenhum alerta saiu');
    }

    #[TestDox('Irmão: a mesma pessoa, a meta do escritório da sessão — renomear funciona')]
    public function testRenomearMetaDoProprioEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUsuarioEm($tenantA, $tenantB);
        $metaA   = $this->criarMeta($tenantA, $usuario, Tarefa::STATUS_PENDENTE);
        $antes   = $this->fotografar($metaA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $usuario, $tenantA);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$metaA}/renomear", [
            '_token' => 'TOKEN_renomear_tarefa_' . $metaA,
            'titulo' => 'Nome novo da meta',
        ]);

        self::assertResponseRedirects();
        self::assertNotFalse($antes);
        self::assertNotSame($antes['titulo'], $this->fotografar($metaA)['titulo'] ?? null, 'a meta de A foi renomeada');
    }
}
