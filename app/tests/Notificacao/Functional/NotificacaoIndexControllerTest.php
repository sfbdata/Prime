<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Controller\NotificacaoController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(NotificacaoController::class)]
final class NotificacaoIndexControllerTest extends JusPrimeWebTestCase
{
    /** Deve casar com NotificacaoController::PER_PAGE. */
    private const PER_PAGE = 20;

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Paginacao Notif ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('notif_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Notif');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    /**
     * Cria $quantidade notificações pessoais (tipo tarefa_criada → não habilita a
     * aba de Gestão, então só a lista pessoal é renderizada).
     */
    private function criarNotificacoesPessoais(User $usuario, int $quantidade): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        for ($i = 0; $i < $quantidade; $i++) {
            $notif = new Notificacao();
            $notif->setUsuario($usuario);
            $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
            $notif->setTitulo('Notificação ' . $i);
            $em->persist($notif);
        }

        $em->flush();
    }

    #[TestDox('Página 1 exibe PER_PAGE notificações e renderiza o navegador de páginas')]
    public function testPrimeiraPaginaLimitaEMostraPager(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->criarNotificacoesPessoais($user, self::PER_PAGE + 5);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/notificacoes');

        self::assertResponseIsSuccessful();
        self::assertCount(self::PER_PAGE, $crawler->filter('.notif-check'));
        self::assertGreaterThan(0, $crawler->filter('.pagination')->count(), 'pager deveria aparecer com mais de uma página');
    }

    #[TestDox('Página 2 exibe o restante das notificações')]
    public function testSegundaPaginaExibeRestante(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->criarNotificacoesPessoais($user, self::PER_PAGE + 5);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/notificacoes?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.notif-check'));
    }

    #[TestDox('Página além do total é limitada à última página (sem erro)')]
    public function testPaginaAlemDoTotalFazClamp(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $this->criarNotificacoesPessoais($user, self::PER_PAGE + 5);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/notificacoes?page=999');

        self::assertResponseIsSuccessful();
        // Clamp para a última página (2), que tem os 5 restantes.
        self::assertCount(5, $crawler->filter('.notif-check'));
    }
}
