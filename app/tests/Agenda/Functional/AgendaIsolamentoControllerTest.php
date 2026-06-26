<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Controller\AgendaController;
use App\Entity\Agenda\Evento;
use App\Entity\Agenda\LegendaCor;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Isolamento multi-tenant do AgendaController via HTTP. Gestores isSystem (que bypassam o
 * PermissionChecker) provam que é o TenantFilter na camada de dados que fecha o vazamento.
 * Cobre IDOR (show/editar/excluir/cancelar), a não-remoção de legendas de outro tenant, e a
 * rejeição de participante de outro escritório.
 */
#[CoversClass(AgendaController::class)]
final class AgendaIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Show/editar de evento de outro tenant retorna 404')]
    public function testShowEditarIsolamPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $eventoB = $this->criarEvento($tenantB, $gestorB);
        $id = (int) $eventoB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/agenda/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar evento de outro tenant');

        $client->request('GET', "/agenda/{$id}/editar");
        self::assertResponseStatusCodeSame(404, 'editar não pode revelar evento de outro tenant');
    }

    #[TestDox('Excluir/cancelar evento de outro tenant retorna 404 e não altera o evento')]
    public function testExcluirCancelarIsolamPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $eventoB = $this->criarEvento($tenantB, $gestorB);
        $id = (int) $eventoB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('POST', "/agenda/{$id}/excluir");
        self::assertResponseStatusCodeSame(404, 'excluir não pode tocar evento de outro tenant');

        $client->request('POST', "/agenda/{$id}/cancelar");
        self::assertResponseStatusCodeSame(404, 'cancelar não pode tocar evento de outro tenant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        $intacto = $em->find(Evento::class, $id);
        self::assertNotNull($intacto);
        self::assertSame(Evento::STATUS_AGENDADO, $intacto->getStatus(), 'evento de outro tenant não pode ter sido cancelado');
    }

    #[TestDox('Salvar legendas de um tenant não apaga as legendas de outro')]
    public function testSalvarLegendasNaoApagaDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $legendaB = $this->criarLegenda($tenantB, 'Prazo B');
        $idLegB = (int) $legendaB->getId();
        $this->limparIdentityMap();

        // Gestor A salva uma lista vazia: o mass-delete só pode tocar legendas de A.
        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('POST', '/agenda/legendas/salvar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], json_encode(['legendas' => []]));
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(LegendaCor::class, $idLegB), 'legenda de outro tenant foi apagada');
    }

    #[TestDox('Criar evento via AJAX rejeita participante de outro tenant')]
    public function testCriarAjaxRejeitaParticipanteDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $usuarioB = $this->criarGestor($tenantB, 'usuarioB_' . uniqid() . '@test.com');
        $idUserB = (int) $usuarioB->getId();
        $titulo = 'EventoAjax' . uniqid();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $payload = json_encode([
            'titulo' => $titulo,
            'dataInicio' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
            'duracao' => 1,
            'participantes' => [$idUserB],
        ]);
        $client->request('POST', '/agenda/criar-ajax', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], $payload);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        $evento = $em->getRepository(Evento::class)->findOneBy(['titulo' => $titulo]);
        self::assertNotNull($evento);
        self::assertCount(0, $evento->getParticipantes(), 'participante de outro tenant não pode ter sido vinculado');
    }

    // ----------------------------------------------------------------- helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
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

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant AGE ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

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

    private function criarEvento(Tenant $tenant, User $criador): Evento
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $evento = new Evento();
        $evento->setTitulo('Evento ' . $n . ' ' . uniqid());
        $evento->setDataInicio((new \DateTimeImmutable('+' . $n . ' days'))->setTime(9, 0));
        $evento->setDataFim((new \DateTimeImmutable('+' . $n . ' days'))->setTime(10, 0));
        $evento->setCriador($criador);
        $evento->setTenant($tenant);
        $em->persist($evento);
        $em->flush();

        return $evento;
    }

    private function criarLegenda(Tenant $tenant, string $nome): LegendaCor
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $legenda = new LegendaCor();
        $legenda->setNome($nome);
        $legenda->setCor('#00a65a');
        $legenda->setOrdem(0);
        $legenda->setTenant($tenant);
        $em->persist($legenda);
        $em->flush();

        return $legenda;
    }
}
