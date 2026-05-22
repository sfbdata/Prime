<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaController::class)]
final class AlterarPrioridadeControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Prioridade ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_prioridade_' . uniqid() . '@test.com');
        $user->setFullName('Admin Prioridade');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-PRIO-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
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

    #[TestDox('POST prioridade sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/prioridade');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST prioridade com CSRF inválido retorna 403')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/prioridade", [
            '_token'     => 'token_invalido',
            'prioridade' => 'urgente',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST prioridade com valor inválido retorna 422')]
    public function testValorInvalidoRetorna422(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/prioridade", [
            '_token'     => $this->gerarCsrf('pasta_prioridade_' . $pasta->getId()),
            'prioridade' => 'invalido',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST prioridade com valor urgente retorna 200 com badgeClass e label')]
    public function testAlterarParaUrgenteRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/prioridade", [
            '_token'     => $this->gerarCsrf('pasta_prioridade_' . $pasta->getId()),
            'prioridade' => 'urgente',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('text-bg-danger', $data['badgeClass']);
        self::assertSame('Urgente', $data['label']);
    }

    #[TestDox('POST prioridade com valor normal retorna 200 com badge secondary')]
    public function testAlterarParaNormalRetorna200(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/prioridade", [
            '_token'     => $this->gerarCsrf('pasta_prioridade_' . $pasta->getId()),
            'prioridade' => 'normal',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('text-bg-secondary', $data['badgeClass']);
        self::assertSame('Normal', $data['label']);
    }
}
