<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\DesligaTenantFilterNaRequisicao;
use App\Tests\Tenant\Support\DesligaEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TarefaController::class)]
final class ExcluirTarefaControllerTest extends JusPrimeWebTestCase
{
    use DesligaTenantFilterNaRequisicao;

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
        $tenant->setName('Tenant Excluir ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Cria uma meta. A pasta tem $autorPasta como criador (garante acesso via
     * verificarAcessoTarefa); a meta tem $criadorMeta como criador (regra de exclusão).
     */
    private function criarTarefa(Tenant $tenant, User $autorPasta, User $criadorMeta): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-DEL-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autorPasta);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta a excluir');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criadorMeta);
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

    #[TestDox('Criador exclui meta recém-criada: redireciona e a meta some do banco')]
    public function testCriadorExcluiMetaRecente(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor);
        $id     = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => $this->gerarCsrf('excluir_tarefa_' . $id),
        ]);

        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Tarefa::class, $id), 'meta deveria ter sido excluída');
    }

    #[TestDox('Não-criador (mesmo tenant) não exclui: meta permanece')]
    public function testNaoCriadorNaoExclui(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $outro  = $this->criarUsuario($tenant, 'outro_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor);
        $id     = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        DesligaEdicaoSemPrazo::no(static::getContainer()->get(EntityManagerInterface::class), $tenant, static::getContainer(), $outro);
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => $this->gerarCsrf('excluir_tarefa_' . $id),
        ]);

        // Controller captura o AccessDenied do UseCase e redireciona com flash.
        self::assertResponseStatusCodeSame(302);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Tarefa::class, $id), 'meta de terceiro não pode ser excluída');
    }

    #[TestDox('Usuário do tenant B não exclui meta do tenant A (IDOR cross-tenant)')]
    public function testCrossTenantNaoExclui(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autorA   = $this->criarUsuario($tenantA, 'autor_a_' . uniqid() . '@test.com');
        $atacante = $this->criarUsuario($tenantB, 'atacante_b_' . uniqid() . '@test.com');
        $tarefa   = $this->criarTarefa($tenantA, $autorA, $autorA);
        $id       = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $atacante, $tenantB);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear(); // identity map vazia, como em produção por request → ParamConverter consulta o banco com o filtro

        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => $this->gerarCsrf('excluir_tarefa_' . $id),
        ]);

        // Com o filtro de tenant, o find() cross-tenant retorna null → 404 antes do guard
        self::assertResponseStatusCodeSame(404);

        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(Tarefa::class, $id), 'meta do tenant A deve permanecer intacta');
    }

    /**
     * PAS-048 / PMC-030: o teste acima fica verde só pelo TenantFilter (o find() devolve null).
     * Aqui o filtro sai DENTRO da requisição: a meta do escritório A é carregada, e quem recusa é o
     * guarda do controller (`verificarAcessoTarefa`). Desde a SEC-013 ele confere PRIMEIRO a posse
     * (tenant da meta = tenant da sessão) e responde 404, como todo recurso cross-tenant. Sem o
     * guarda, o UseCase ainda recusaria (tenant da pasta), mas com um 302 de flash: o 404 com o
     * filtro desligado é o que distingue a barreira do controller. O caso que só a posse cobre
     * (autor vinculado aos DOIS escritórios) está em TarefaPosseDoEscritorioTest.
     */
    #[TestDox('PMC-030: meta de OUTRO escritório com o TenantFilter desligado — o guarda do controller recusa e a meta não muda')]
    public function testCrossTenantComFiltroDesligadoOGuardaRecusa(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autorA   = $this->criarUsuario($tenantA, 'autor_a_' . uniqid() . '@test.com');
        $atacante = $this->criarUsuario($tenantB, 'atacante_b_' . uniqid() . '@test.com');
        $tarefa   = $this->criarTarefa($tenantA, $autorA, $autorA);
        $id       = (int) $tarefa->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $atacante, $tenantB);

        $conexao = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $antes   = $conexao->fetchAssociative('SELECT titulo, tenant_id FROM tarefa WHERE id = ?', [$id]);
        self::assertNotFalse($antes);

        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => $this->gerarCsrf('excluir_tarefa_' . $id),
        ]);

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, $conexao->fetchAssociative('SELECT titulo, tenant_id FROM tarefa WHERE id = ?', [$id]), 'a meta do escritório A continua intacta');
    }

    #[TestDox('Criador não exclui meta fora da janela de 24h: meta permanece')]
    public function testForaDaJanelaNaoExclui(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor);
        $id     = (int) $tarefa->getId();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Envelhece a meta para além da janela de 24h.
        $em->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => '2020-01-01 00:00:00', 'id' => $id]
        );
        DesligaEdicaoSemPrazo::no($em, $tenant, static::getContainer());
        $em->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => $this->gerarCsrf('excluir_tarefa_' . $id),
        ]);

        // UseCase lança MetaNaoExcluivelException → controller faz flash + redirect.
        self::assertResponseStatusCodeSame(302);

        $em->clear();
        self::assertNotNull($em->find(Tarefa::class, $id), 'meta fora da janela não pode ser excluída');
    }

    #[TestDox('CSRF inválido retorna 403 mesmo sendo o criador')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $autor  = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $autor, $autor);
        $id     = (int) $tarefa->getId();

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/{$id}/excluir", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Tarefa::class, $id));
    }

    #[TestDox('Edição sem prazo: o primário (opção ligada) exclui a meta de outra pessoa fora das 24h, e isso entra na auditoria')]
    public function testPrimarioExcluiMetaAlheiaForaDaJanela(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $primario = $this->criarUsuario($tenant, 'primario_' . uniqid() . '@test.com');
        $id       = (int) $this->criarTarefa($tenant, $autor, $autor)->getId();
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('UPDATE tarefa SET data_criacao = :d WHERE id = :id', ['d' => '2020-01-01 00:00:00', 'id' => $id]);
        $em->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $primario, $tenant);
        $client->request('POST', "/tarefas/{$id}/excluir", ['_token' => $this->gerarCsrf('excluir_tarefa_' . $id)]);

        self::assertResponseStatusCodeSame(302);
        $em->clear();
        self::assertNull($em->find(Tarefa::class, $id));
        $linha = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'sp_excluir', 'entityId' => (string) $id]);
        self::assertNotNull($linha);
        self::assertSame($primario->getId(), $linha->getActorUserId());
        self::assertSame('Usuário primário', $linha->getChanges()['papel'] ?? null);
    }
}
