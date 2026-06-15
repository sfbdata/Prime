<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
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
final class EditarTarefaMensagemTest extends JusPrimeWebTestCase
{
    /**
     * Cria um usuário COMUM (ROLE_USER, sem super admin global) com acesso ao
     * módulo dentro do próprio tenant via TenantRole de sistema. Assim a camada
     * de autorização (assertAccess/verificarAcessoTarefa) é de fato exercida —
     * um ROLE_SUPER_ADMIN bypassaria a verificação e mascararia a cobertura.
     */
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

        // TenantRole de sistema → canAccessModule libera o módulo dentro DESTE
        // tenant, sem o bypass global do ROLE_SUPER_ADMIN.
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
        $tenant->setName('Tenant Mensagem ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarMensagem(Tenant $tenant, User $autor): TarefaMensagem
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TEST-MSG-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($autor); // garante acesso via verificarAcessoTarefa
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta de teste');
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $em->persist($tarefa);

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Conteúdo original');
        $em->persist($mensagem);
        $em->flush();

        return $mensagem;
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

    #[TestDox('Autor edita a própria mensagem: 200, conteúdo atualizado e marca editado')]
    public function testAutorEditaComSucesso(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $mensagem = $this->criarMensagem($tenant, $autor);
        $msgId    = (int) $mensagem->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", [
            '_token'   => $this->gerarCsrf('editar_mensagem_tarefa_' . $msgId),
            'conteudo' => 'Conteúdo editado',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Conteúdo editado', $data['conteudo']);
        self::assertNotEmpty($data['editadoEm']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $atualizada = $em->find(TarefaMensagem::class, $msgId);
        self::assertSame('Conteúdo editado', $atualizada->getMensagem());
        self::assertNotNull($atualizada->getEditadoEm());
    }

    /**
     * Regra de AUTORIA: outro usuário do MESMO tenant (com acesso legítimo à
     * tarefa) não pode editar a mensagem de terceiro. Aqui a guarda de tenant
     * passa e quem barra é a checagem de autoria → 403 JSON com 'erro'.
     */
    #[TestDox('Usuário não-autor do mesmo tenant recebe 403 (regra de autoria)')]
    public function testNaoAutorRecebe403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $outro    = $this->criarUsuario($tenant, 'outro_' . uniqid() . '@test.com');
        $mensagem = $this->criarMensagem($tenant, $autor);
        $msgId    = (int) $mensagem->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $outro, $tenant);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", [
            '_token'   => $this->gerarCsrf('editar_mensagem_tarefa_' . $msgId),
            'conteudo' => 'Tentativa de edição alheia',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);

        // A mensagem não pode ter sido alterada
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $intacta = $em->find(TarefaMensagem::class, $msgId);
        self::assertSame('Conteúdo original', $intacta->getMensagem());
        self::assertNull($intacta->getEditadoEm());
    }

    /**
     * IDOR cross-tenant: um usuário do tenant B, mesmo com acesso legítimo ao
     * módulo no SEU tenant, não pode editar uma mensagem cuja tarefa pertence
     * ao tenant A (onde ele não tem vínculo). A guarda verificarAcessoTarefa
     * barra ANTES da regra de autoria → AccessDenied (403, resposta não-JSON).
     */
    #[TestDox('Usuário do tenant B não edita mensagem do tenant A (IDOR cross-tenant)')]
    public function testCrossTenantNaoEditaMensagemDeOutroTenant(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $autorA   = $this->criarUsuario($tenantA, 'autor_a_' . uniqid() . '@test.com');
        $atacante = $this->criarUsuario($tenantB, 'atacante_b_' . uniqid() . '@test.com');
        $mensagem = $this->criarMensagem($tenantA, $autorA);
        $msgId    = (int) $mensagem->getId();

        $this->instalarCsrfStorage();
        // Atacante autenticado no PRÓPRIO tenant (B), onde tem acesso ao módulo
        $this->logarComTenant($client, $atacante, $tenantB);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", [
            '_token'   => $this->gerarCsrf('editar_mensagem_tarefa_' . $msgId),
            'conteudo' => 'Sequestro cross-tenant',
        ]);

        self::assertResponseStatusCodeSame(403);

        // O dado do tenant A permanece intacto
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $intacta = $em->find(TarefaMensagem::class, $msgId);
        self::assertSame('Conteúdo original', $intacta->getMensagem());
        self::assertNull($intacta->getEditadoEm());
    }

    #[TestDox('CSRF inválido retorna 403 mesmo sendo o autor')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $mensagem = $this->criarMensagem($tenant, $autor);
        $msgId    = (int) $mensagem->getId();

        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", [
            '_token'   => 'token_invalido',
            'conteudo' => 'Conteúdo editado',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('Conteúdo vazio retorna 422')]
    public function testConteudoVazioRetorna422(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $autor    = $this->criarUsuario($tenant, 'autor_' . uniqid() . '@test.com');
        $mensagem = $this->criarMensagem($tenant, $autor);
        $msgId    = (int) $mensagem->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $autor, $tenant);

        $client->request('POST', "/tarefas/mensagem/{$msgId}/editar", [
            '_token'   => $this->gerarCsrf('editar_mensagem_tarefa_' . $msgId),
            'conteudo' => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }
}
