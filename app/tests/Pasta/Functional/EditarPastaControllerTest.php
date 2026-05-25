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
final class EditarPastaControllerTest extends JusPrimeWebTestCase
{
    private function criarCenario(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Editar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_editar_' . uniqid() . '@test.com');
        $user->setFullName('Admin Editar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $pasta = new Pasta();
        $pasta->setNup('NUP-EDIT-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $em->persist($pasta);

        $em->flush();

        return [$user, $tenant, $pasta];
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

    #[TestDox('POST /{id}/editar com dados válidos atualiza pasta e redireciona para pasta_show')]
    public function testSucessoEditaPasta(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $novoNup                 = 'NUP-NOVO-' . strtoupper(uniqid());

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token'       => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'          => $novoNup,
            'nome_cliente' => 'Cliente Editado',
            'nome_acao'    => 'Ação Editada',
            'situacao'     => Pasta::SITUACAO_ARQUIVADA,
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $atualizada = $em->find(Pasta::class, $pasta->getId());

        self::assertNotNull($atualizada);
        self::assertSame($novoNup, $atualizada->getNup());
        self::assertSame(Pasta::SITUACAO_ARQUIVADA, $atualizada->getSituacao());
    }

    #[TestDox('POST /{id}/editar com CSRF inválido retorna 403')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token' => 'token-invalido',
            'nup'    => 'QUALQUER-NUP',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST /{id}/editar com NUP vazio exibe flash error e não altera a pasta')]
    public function testNupVazioNaoAlteraPasta(): void
    {
        $client                  = static::createClient();
        [$user, $tenant, $pasta] = $this->criarCenario();
        $nupOriginal             = $pasta->getNup();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/' . $pasta->getId() . '/editar', [
            '_token' => $this->gerarCsrf('edit_pasta_' . $pasta->getId()),
            'nup'    => '   ',
        ]);

        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $inalterada = $em->find(Pasta::class, $pasta->getId());

        self::assertNotNull($inalterada);
        self::assertSame($nupOriginal, $inalterada->getNup());
    }
}
