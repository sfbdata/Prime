<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Controller\AgendaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C4c — os endpoints AJAX/JSON mutantes da Agenda (criar evento via modal, salvar legendas) passam
 * a exigir o token CSRF no header X-CSRF-Token (token 'ajax'). Sem token → 403; com token válido →
 * sucesso. O CSRF é checado APÓS o guard de permissão de módulo (a falha de autz tem prioridade e
 * fica testável). Os demais POSTs da Agenda já têm CSRF (excluir/cancelar/atualizar-datas, ou form).
 */
#[CoversClass(AgendaController::class)]
final class AgendaCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('criar evento via AJAX: sem token CSRF 403, com token sucesso')]
    public function testCriarAjax(): void
    {
        [$client] = $this->preparar();
        $payload  = [
            'titulo'     => 'Evento CSRF ' . uniqid(),
            'dataInicio' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
            'duracao'    => 1,
        ];

        $this->postJson($client, '/agenda/criar-ajax', $payload);
        self::assertResponseStatusCodeSame(403, 'criar evento sem CSRF deveria ser barrado');

        $this->postJson($client, '/agenda/criar-ajax', $payload, 'TOKEN_ajax');
        $this->assertSuccessJson($client);
    }

    #[TestDox('salvar legendas: sem token CSRF 403, com token sucesso')]
    public function testSalvarLegendas(): void
    {
        [$client] = $this->preparar();

        $this->postJson($client, '/agenda/legendas/salvar', ['legendas' => []]);
        self::assertResponseStatusCodeSame(403, 'salvar legendas sem CSRF deveria ser barrado');

        $this->postJson($client, '/agenda/legendas/salvar', ['legendas' => []], 'TOKEN_ajax');
        $this->assertSuccessJson($client);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: KernelBrowser, 1: Tenant, 2: User} */
    private function preparar(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant);
        $this->logarComTenant($client, $gestor, $tenant);

        return [$client, $tenant, $gestor];
    }

    /** @param array<string,mixed> $payload */
    private function postJson(KernelBrowser $client, string $url, array $payload, ?string $csrf = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        $client->request('POST', $url, [], [], $server, (string) json_encode($payload));
    }

    private function assertSuccessJson(KernelBrowser $client): void
    {
        self::assertResponseIsSuccessful('com CSRF válido a requisição deveria suceder');
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($dados);
        self::assertTrue($dados['success'] ?? false, 'a mutação deveria ter sido confirmada (success=true)');
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
        $tenant->setName('Tenant AGE CSRF ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('gestor_age_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Agenda CSRF');
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
}
