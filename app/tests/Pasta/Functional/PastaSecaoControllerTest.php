<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaSecaoController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PastaSecaoController::class)]
final class PastaSecaoControllerTest extends WebTestCase
{
    private function criarUsuarioAdmin(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Secao ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_secao_' . uniqid() . '@test.com');
        $user->setFullName('Admin Secao');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setTenant($tenant);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarPasta(): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('TEST-SEC-' . uniqid());
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, User $autor): PastaSecao
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($autor->getTenant());
        $secao->setNome('Seção de Teste');
        $secao->setOrdem(1);
        $em->persist($secao);
        $em->flush();

        return $secao;
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

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    // ── Criar ──────────────────────────────────────────────────────────────────

    #[TestDox('POST criar seção sem autenticação redireciona para login')]
    public function testCriarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/pasta/1/secao');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST criar seção com CSRF inválido retorna 400')]
    public function testCriarCsrfInvalidoRetorna400(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => 'token_invalido',
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    #[TestDox('POST criar seção com nome vazio retorna 422')]
    public function testCriarNomeVazioRetorna422(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('erro', $data);
    }

    #[TestDox('POST criar seção com nome válido retorna 201 com id e nome')]
    public function testCriarComSucessoRetorna201(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/{$pasta->getId()}/secao", [
            '_token' => $this->csrf('pasta_secao_criar_' . $pasta->getId()),
            'nome'   => 'Petições',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('PETIÇÕES', $data['nome']);
        self::assertArrayHasKey('id', $data);
        self::assertGreaterThan(0, $data['id']);
    }

    // ── Renomear ───────────────────────────────────────────────────────────────

    #[TestDox('POST renomear seção com nome válido retorna 200')]
    public function testRenomearComSucessoRetorna200(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $secao  = $this->criarSecao($pasta, $user);
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/renomear", [
            '_token' => $this->csrf('pasta_secao_renomear_' . $secao->getId()),
            'nome'   => 'Contratos',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame('CONTRATOS', $data['nome']);
    }

    #[TestDox('POST renomear seção inexistente retorna 404')]
    public function testRenomearSecaoInexistenteRetorna404(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', '/pasta/secao/999999/renomear', [
            '_token' => $this->csrf('pasta_secao_renomear_999999'),
            'nome'   => 'Teste',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ────────────────────────────────────────────────────────────────

    #[TestDox('POST excluir seção com sucesso retorna 200')]
    public function testExcluirComSucessoRetorna200(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $secao  = $this->criarSecao($pasta, $user);
        $secaoId = $secao->getId();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', "/pasta/secao/{$secaoId}/excluir", [
            '_token' => $this->csrf('pasta_secao_excluir_' . $secaoId),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->find(PastaSecao::class, $secaoId));
    }

    #[TestDox('POST excluir seção com CSRF inválido retorna 400')]
    public function testExcluirCsrfInvalidoRetorna400(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioAdmin();
        $pasta  = $this->criarPasta();
        $secao  = $this->criarSecao($pasta, $user);
        $client->loginUser($user);

        $client->request('POST', "/pasta/secao/{$secao->getId()}/excluir", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}
