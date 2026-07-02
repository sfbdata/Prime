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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(TarefaController::class)]
final class TarefaMinhasControllerTest extends JusPrimeWebTestCase
{
    private function autenticar(KernelBrowser $client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Metas ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('metas_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Metas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel isSystem → bypassa o PermissionChecker (módulo 'tarefas').
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    private function criarMeta(Tenant $tenant, User $responsavel, string $titulo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('META-' . uniqid());
        $pasta->setNomeCliente('Cliente Meta');
        $pasta->setResponsavel($responsavel);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição da meta');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    #[TestDox('GET /tarefas/minhas autenticado retorna 200 e renderiza a barra de filtro')]
    public function testExibeBarraDeFiltro(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $body);
        self::assertStringContainsString('js-filtro-busca', $body);
    }

    #[TestDox('GET /tarefas/minhas exibe a meta do usuário')]
    public function testExibeMinhaMeta(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar contestação');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Protocolar contestação', (string) $client->getResponse()->getContent());
    }

    #[TestDox('XHR em /tarefas/minhas devolve só o fragmento, sem o layout nem a barra')]
    public function testXhrRetornaFragmentoSemLayout(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Elaborar parecer');

        $client->xmlHttpRequest('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Elaborar parecer', $body);
        self::assertStringNotContainsString('<!DOCTYPE', $body);
        self::assertStringNotContainsString('data-filtro-root', $body);
    }

    #[TestDox('XHR com busca filtra as metas por título')]
    public function testXhrFiltraPorBusca(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');
        $this->criarMeta($tenant, $usuario, 'Agendar audiência');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'recurso']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Protocolar recurso', $body);
        self::assertStringNotContainsString('Agendar audiência', $body);
    }

    #[TestDox('XHR com busca sem correspondência mostra o estado vazio filtrado')]
    public function testXhrBuscaSemResultado(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma meta encontrada', (string) $client->getResponse()->getContent());
    }
}
