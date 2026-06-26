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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C4c — marcar notificação como lida (AJAX) passa a exigir o token CSRF no header X-CSRF-Token
 * (token 'ajax'). Sem token → 403; com token válido → sucesso. O CSRF é checado APÓS o guard de
 * posse (notificação do próprio usuário), que tem prioridade e fica testável. O par sem/com token,
 * sobre a MESMA notificação do usuário logado, prova que o 403 vem do CSRF e não do guard de posse.
 */
#[CoversClass(NotificacaoController::class)]
final class NotificacaoCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('marcar notificação como lida: sem token CSRF 403, com token sucesso')]
    public function testMarcarComoLida(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $tenant = $this->criarTenant();
        $user   = $this->criarUsuario($tenant);
        $id     = (int) $this->criarNotificacao($user)->getId();
        $url    = "/notificacoes/{$id}/ler";

        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', $url);
        self::assertResponseStatusCodeSame(403, 'marcar como lida sem CSRF deveria ser barrado');

        $client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful('marcar como lida com CSRF válido deveria suceder');
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['success'] ?? false, 'a mutação deveria ter sido confirmada (success=true)');
    }

    // ----------------------------------------------------------------- helpers

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
        $tenant->setName('Tenant CSRF Notif ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('notif_csrf_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Notif CSRF');
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

    private function criarNotificacao(User $usuario): Notificacao
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $notif = new Notificacao();
        $notif->setUsuario($usuario);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo('Notificação ' . uniqid());
        $em->persist($notif);
        $em->flush();

        return $notif;
    }
}
