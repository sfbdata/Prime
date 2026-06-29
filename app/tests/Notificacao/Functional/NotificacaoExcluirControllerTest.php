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
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(NotificacaoController::class)]
final class NotificacaoExcluirControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Excluir Notif ' . uniqid());
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

    private function criarNotificacao(User $usuario, Tenant $tenant): Notificacao
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $notif = new Notificacao();
        $notif->setUsuario($usuario);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo('Notificação ' . uniqid());
        $em->persist($notif);
        $em->flush();

        return $notif;
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

    #[TestDox('Usuário exclui as notificações selecionadas: responde success e os registros somem do banco')]
    public function testExcluiNotificacoesSelecionadas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $a      = (int) $this->criarNotificacao($user, $tenant)->getId();
        $b      = (int) $this->criarNotificacao($user, $tenant)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/notificacoes/excluir', [
            '_token' => $this->gerarCsrf('excluir_notificacoes'),
            'ids'    => [$a, $b],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(2, $data['excluidas']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Notificacao::class, $a));
        self::assertNull($em->find(Notificacao::class, $b));
    }

    #[TestDox('Não exclui notificação de outro usuário mesmo que o ID seja enviado (isolamento por posse)')]
    public function testNaoExcluiNotificacaoDeOutroUsuario(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $outro  = $this->criarUsuario($tenant);
        $minha  = (int) $this->criarNotificacao($user, $tenant)->getId();
        $alheia = (int) $this->criarNotificacao($outro, $tenant)->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/notificacoes/excluir', [
            '_token' => $this->gerarCsrf('excluir_notificacoes'),
            'ids'    => [$minha, $alheia],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        // Só a do próprio usuário é contabilizada/excluída.
        self::assertSame(1, $data['excluidas']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Notificacao::class, $minha));
        self::assertNotNull($em->find(Notificacao::class, $alheia), 'notificação de terceiro não pode ser excluída');
    }

    #[TestDox('CSRF inválido retorna 403 e nada é excluído')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $id     = (int) $this->criarNotificacao($user, $tenant)->getId();

        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/notificacoes/excluir', [
            '_token' => 'token_invalido',
            'ids'    => [$id],
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(Notificacao::class, $id));
    }
}
