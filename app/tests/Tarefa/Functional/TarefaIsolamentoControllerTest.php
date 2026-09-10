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

/**
 * Isolamento multi-tenant do TarefaController via HTTP. Gestores isSystem (que bypassam o
 * PermissionChecker) provam que é o TenantFilter na camada de dados — não a permissão — que
 * fecha o vazamento. em->clear() após os fixtures força o find()/ParamConverter a executar SQL
 * real. O caso editarMensagem cobre a filha TarefaMensagem, carregada por id direto.
 */
#[CoversClass(TarefaController::class)]
final class TarefaIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;
    private bool $csrfInstalado = false;

    #[TestDox('Dono acessa a própria tarefa; gestor de outro tenant recebe 404 (show)')]
    public function testShowIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $tarefaB = $this->criarTarefa($tenantB, $gestorB);
        $id = (int) $tarefaB->getId();
        $this->limparIdentityMap();

        // dono (gestor do tenant B, criador da pasta) acessa normalmente
        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', "/tarefas/{$id}");
        self::assertResponseIsSuccessful();

        // gestor de outro tenant — mesmo isSystem — recebe 404 pelo filtro de dados
        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/tarefas/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar tarefa de outro tenant');
    }

    #[TestDox('Editar mensagem de tarefa de outro tenant retorna 404 (filha carregada por id direto)')]
    public function testEditarMensagemIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $tarefaB = $this->criarTarefa($tenantB, $gestorB);
        $mensagemB = $this->criarMensagem($tarefaB, $gestorB);
        $idMsg = (int) $mensagemB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('POST', "/tarefas/mensagem/{$idMsg}/editar", [
            'conteudo' => 'tentativa cross-tenant',
            '_token'   => 'qualquer',
        ]);
        self::assertResponseStatusCodeSame(404, 'editar mensagem não pode tocar mensagem de outro tenant');

        // prova de que a 404 veio do filtro: a linha existe quando o filtro é desligado
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(TarefaMensagem::class, $idMsg));
    }

    // ----------------------------------------------------------------- helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant TAREFA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    /**
     * Cria uma tarefa cuja pasta tem $criador como criadoPor — garante acesso via
     * verificarAcessoTarefa para o próprio tenant (controle positivo do teste de show).
     */
    private function criarTarefa(Tenant $tenant, User $criador): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('TAR-' . (++$this->seq) . '-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('Descrição');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criador);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    private function criarMensagem(Tarefa $tarefa, User $autor): TarefaMensagem
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $mensagem = new TarefaMensagem();
        $mensagem->setTarefa($tarefa);
        $mensagem->setUsuario($autor);
        $mensagem->setMensagem('Mensagem original');
        $mensagem->setTenant($tarefa->getTenant());
        $em->persist($mensagem);
        $em->flush();

        return $mensagem;
    }

    #[TestDox('Acompanhar meta de outro tenant retorna 404 — e o irmão do mesmo tenant funciona')]
    public function testAcompanharIsolaPorTenant(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'acompA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'acompB_' . uniqid() . '@test.com');

        $doA = $this->criarTarefa($tenantA, $gestorA);
        $doB = $this->criarTarefa($tenantB, $gestorB);
        $idA = (int) $doA->getId();
        $idB = (int) $doB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        // O IRMÃO do próprio tenant prova que a rota, o CSRF e a permissão estão OK — sem
        // isso um 404 no cross-tenant poderia estar vindo de outra barreira qualquer, e o
        // teste passaria verde provando a coisa errada.
        $client->request('POST', "/tarefas/{$idA}/acompanhar", [
            '_token' => $this->tokenCsrf('acompanhar_tarefa_' . $idA),
        ]);
        self::assertResponseIsSuccessful('A meta do próprio tenant tem de aceitar o acompanhamento.');

        $client->request('POST', "/tarefas/{$idB}/acompanhar", [
            '_token' => $this->tokenCsrf('acompanhar_tarefa_' . $idB),
        ]);
        self::assertResponseStatusCodeSame(404, 'acompanhar não pode alcançar meta de outro tenant');
    }

    #[TestDox('Acompanhar sem token CSRF válido é recusado')]
    public function testAcompanharExigeCsrf(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'csrf_' . uniqid() . '@test.com');
        $tarefa = $this->criarTarefa($tenant, $gestor);
        $id     = (int) $tarefa->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Minhas Metas não lista meta de outro tenant em nenhuma aba')]
    public function testMinhasMetasIsolaPorTenantEmTodasAsAbas(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'listaA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'listaB_' . uniqid() . '@test.com');

        $doB = $this->criarTarefa($tenantB, $gestorB);
        $doB->setTitulo('SEGREDO DO TENANT B');
        $doB->addResponsavel($gestorA);       // o vínculo existe, mas atravessa tenants
        $doB->alternarAcompanhamento($gestorA);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        foreach (['responsavel', 'criei', 'acompanhando', 'todas'] as $aba) {
            $client->request('GET', '/tarefas/minhas?aba=' . $aba);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString(
                'SEGREDO DO TENANT B',
                (string) $client->getResponse()->getContent(),
                "A aba '{$aba}' vazou meta de outro tenant.",
            );
        }
    }

    /**
     * Troca o armazenamento do token CSRF por um previsível — mesma receita do
     * `PastaPagamentoControllerTest`. Sem isso o teste teria de raspar o token da tela, e o
     * gerador real depende de sessão, que ainda não existe no primeiro request.
     *
     * Uma vez só: o contêiner recusa substituir serviço já inicializado.
     */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function tokenCsrf(string $id): string
    {
        return 'TOKEN_' . $id;
    }
}
