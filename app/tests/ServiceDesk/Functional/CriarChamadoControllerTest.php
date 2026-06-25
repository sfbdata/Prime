<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ServiceDeskController::class)]
final class CriarChamadoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('POST /servicedesk/novo abre o chamado e notifica os gestores, exceto o solicitante')]
    public function testCriaChamadoENotificaGestores(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $solicitante = $this->criarUsuario($tenant, 'solic_' . uniqid() . '@test.com');
        $gestor      = $this->criarUsuario($tenant, 'gestor_' . uniqid() . '@test.com');

        $this->logarComTenant($client, $solicitante, $tenant);

        $crawler = $client->request('GET', '/servicedesk/novo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Abrir Chamado')->form([
            'chamado[titulo]'     => 'Não consigo acessar o sistema',
            'chamado[descricao]'  => 'Aparece erro ao fazer login',
            'chamado[categoria]'  => Chamado::CATEGORIA_SOFTWARE,
            'chamado[prioridade]' => Chamado::PRIORIDADE_ALTA,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamados = $em->getRepository(Chamado::class)->findAll();
        self::assertCount(1, $chamados, 'o chamado deve ter sido criado');
        self::assertSame('Não consigo acessar o sistema', $chamados[0]->getTitulo());

        $notifsGestor = $em->getRepository(Notificacao::class)->findBy(['usuario' => $gestor]);
        self::assertCount(1, $notifsGestor, 'o gestor deve receber a notificação de novo chamado');
        self::assertSame(Notificacao::TIPO_SERVICEDESK_NOVO, $notifsGestor[0]->getTipo());

        $notifsSolicitante = $em->getRepository(Notificacao::class)->findBy(['usuario' => $solicitante]);
        self::assertCount(0, $notifsSolicitante, 'o solicitante não é notificado do próprio chamado');
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

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

        // Papel de sistema → gestor do tenant (passa em canAccessModule e canAdminister).
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
}
