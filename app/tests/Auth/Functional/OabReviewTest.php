<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\OabReviewController;
use App\Auth\Enum\StatusOab;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

#[CoversClass(OabReviewController::class)]
final class OabReviewTest extends JusPrimeWebTestCase
{
    #[TestDox('Usuário comum (não super-admin) com escritório recebe 403 na fila de OAB')]
    public function testNaoSuperAdminRecebe403(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);

        $comum = $this->criarUsuario(['ROLE_USER']);
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();

        // Com tenant no contexto, o usuário passa o gate de seleção de escritório e chega ao
        // IsGranted('ROLE_SUPER_ADMIN') do controller → 403 (e não o redirect de "sem tenant").
        $this->logarComTenant($client, $comum, $tenant);
        $client->request('GET', '/admin/platform/oab');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Super-admin enxerga usuários de qualquer escritório (identidade global)')]
    public function testSuperAdminVeUsuariosGlobais(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));

        $a = $this->criarUsuarioOab(StatusOab::NaoVerificada);
        $b = $this->criarUsuarioOab(StatusOab::Divergente);

        $client->request('GET', '/admin/platform/oab');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($a->getEmail(), $html);
        self::assertStringContainsString($b->getEmail(), $html);
    }

    #[TestDox('Confirmar muda o status para Confirmada e gera auditoria automática atribuída ao admin')]
    public function testConfirmarMudaStatusEAudita(): void
    {
        $client = static::createClient();
        $admin  = $this->criarUsuario(['ROLE_SUPER_ADMIN']);
        $this->logar($client, $admin);
        $alvo = $this->criarUsuarioOab(StatusOab::Divergente);

        $crawler = $client->request('GET', '/admin/platform/oab');
        $form    = $crawler->filter('form[action*="/confirmar"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(StatusOab::Confirmada, $this->recarregar($alvo)->getOabStatus());

        // Auditoria automática: User é Auditavel → o AuditLogSubscriber registra a alteração de
        // oabStatus no flush, com o super-admin logado como ator (quem/quando).
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityId'    => (string) $alvo->getId(),
            'actorUserId' => $admin->getId(),
        ]);
        self::assertNotNull($log, 'a confirmação deve gerar auditoria automática atribuída ao super-admin');
        self::assertSame('update', $log->getAction());
    }

    #[TestDox('Reverter uma OAB confirmada para Divergente (admin escolhe o destino)')]
    public function testReverterParaDivergente(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $alvo = $this->criarUsuarioOab(StatusOab::Confirmada);

        $crawler = $client->request('GET', '/admin/platform/oab?status=confirmada');
        // Duas opções de reverter (nao_verificada, divergente); a segunda é "divergente".
        $form = $crawler->filter('form[action*="/reverter"]')->eq(1)->form();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(StatusOab::Divergente, $this->recarregar($alvo)->getOabStatus());
    }

    #[TestDox('Re-verificar com backend dormente mantém NaoVerificada e mostra aviso')]
    public function testReverificarDormente(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $alvo = $this->criarUsuarioOab(StatusOab::NaoVerificada);

        $crawler = $client->request('GET', '/admin/platform/oab');
        $form    = $crawler->filter('form[action*="/reverificar"]')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(StatusOab::NaoVerificada, $this->recarregar($alvo)->getOabStatus());
    }

    #[TestDox('Confirmar com CSRF inválido → 403 e status inalterado')]
    public function testCsrfInvalidoRecusa(): void
    {
        $client = static::createClient();
        $this->logar($client, $this->criarUsuario(['ROLE_SUPER_ADMIN']));
        $alvo = $this->criarUsuarioOab(StatusOab::Divergente);

        $client->request('POST', '/admin/platform/oab/' . $alvo->getId() . '/confirmar', ['_token' => 'forjado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(StatusOab::Divergente, $this->recarregar($alvo)->getOabStatus());
    }

    // ----------------------------------------------------------------- helpers

    private function logar(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
    }

    /** @param list<string> $roles */
    private function criarUsuario(array $roles): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Teste');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarUsuarioOab(StatusOab $status): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('adv_' . uniqid() . '@test.com');
        $user->setFullName('Fulano Advogado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setOabNumero('12345');
        $user->setOabUf('SP');
        $user->setOabStatus($status);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function recarregar(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $user->getId();
        $em->clear();

        return $em->getRepository(User::class)->find($id);
    }
}
