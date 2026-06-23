<?php

declare(strict_types=1);

namespace App\Tests\Termo\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Termo\Controller\TermoController;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TermoController::class)]
final class TermoAceiteControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Termo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('termo_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Termo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function criarUsuarioComum(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('comum_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Comum');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
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

    #[TestDox('Usuário autenticado sem aceite é redirecionado para a tela de aceite')]
    public function testSemAceiteRedirecionaParaTelaDeAceite(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $client->loginUser($user);

        $client->request('GET', '/');

        self::assertResponseRedirects('/termos/aceitar');
    }

    #[TestDox('Requisição XHR sem aceite retorna 403 em vez de redirect')]
    public function testSemAceiteEmXhrRetorna403(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $client->loginUser($user);

        $client->request('GET', '/', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('GET /termos/aceitar renderiza a tela de aceite')]
    public function testTelaDeAceiteRenderiza(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $client->loginUser($user);

        $client->request('GET', '/termos/aceitar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="aceito"]');
    }

    #[TestDox('POST aceite com checkbox marcado registra o aceite e libera o acesso')]
    public function testAceiteRegistraEDesbloqueia(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', '/termos/aceitar', [
            '_token' => 'TOKEN_termo_aceite',
            'aceito' => '1',
        ]);

        self::assertResponseRedirects('/');

        $repo = static::getContainer()->get(AceiteTermoRepository::class);
        self::assertTrue($repo->existeAceiteVigente($user, TermoVigente::VERSAO));
    }

    #[TestDox('POST aceite sem marcar o checkbox volta para a tela de aceite')]
    public function testAceiteSemCheckboxVoltaParaTela(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $this->instalarCsrfStorage();
        $client->loginUser($user);

        $client->request('POST', '/termos/aceitar', [
            '_token' => 'TOKEN_termo_aceite',
        ]);

        self::assertResponseRedirects('/termos/aceitar');

        $repo = static::getContainer()->get(AceiteTermoRepository::class);
        self::assertFalse($repo->existeAceiteVigente($user, TermoVigente::VERSAO));
    }

    #[TestDox('Usuário comum sem tenant alcança a tela de aceite antes de selecionar escritório')]
    public function testUsuarioComumSemTenantVeTelaDeAceite(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuarioComum();
        $client->loginUser($user);

        // Cai no gate ao acessar a home, sem antes ser mandado para seleção de escritório.
        $client->request('GET', '/');
        self::assertResponseRedirects('/termos/aceitar');

        // E a própria tela de aceite renderiza, mesmo sem tenant selecionado (C1).
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="aceito"]');
    }

    #[TestDox('Aceite de versão antiga não libera o acesso à versão vigente')]
    public function testAceiteDeVersaoAntigaNaoLibera(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new AceiteTermo($user, '2020-01-01', '203.0.113.7'));
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/');

        self::assertResponseRedirects('/termos/aceitar');
    }

    #[TestDox('POST aceite com CSRF inválido retorna 403')]
    public function testAceiteComCsrfInvalidoRetorna403(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $client->loginUser($user);

        $client->request('POST', '/termos/aceitar', [
            '_token' => 'token_invalido',
            'aceito' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
