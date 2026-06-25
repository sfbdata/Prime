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
}
