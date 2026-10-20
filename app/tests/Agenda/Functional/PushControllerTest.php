<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\PushController;
use App\Agenda\UseCase\InscreverPushUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(PushController::class)]
#[CoversClass(InscreverPushUseCase::class)]
final class PushControllerTest extends JusPrimeWebTestCase
{
    private const INSCRICAO = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A', 'auth' => 'AAECAwQFBgcICQoLDA0ODw'], 'fuso' => 'America/Manaus'];

    #[TestDox('Sem chaves VAPID no servidor a chave pública vem nula (o navegador não se inscreve)')]
    public function testChaveDesligada(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarUsuario($tenant, true), $tenant);

        $client->request('GET', '/agenda/push/chave');

        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['chave']);
    }

    #[TestDox('Inscrever guarda uma linha por navegador; o mesmo navegador muda de dono; inválido dá 422; sem Agenda dá 403')]
    public function testInscrever(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant, true);
        $colega = $this->criarUsuario($tenant, true);
        $semAgenda = $this->criarUsuario($tenant, false);
        $outroEscritorio = $this->criarTenant();
        $deLa = $this->criarUsuario($outroEscritorio, true);

        $this->logarComTenant($client, $eu, $tenant);
        $this->postar($client, '/agenda/push/inscrever', self::INSCRICAO);
        self::assertResponseIsSuccessful();
        $this->logarComTenant($client, $colega, $tenant);
        $this->postar($client, '/agenda/push/inscrever', self::INSCRICAO);
        self::assertResponseIsSuccessful();

        $linhas = $this->em()->getConnection()->fetchAllAssociative('SELECT usuario_id, fuso FROM agenda_push_inscricao WHERE endpoint = :e', ['e' => self::INSCRICAO['endpoint']]);
        self::assertCount(1, $linhas);
        self::assertSame($colega->getId(), (int) $linhas[0]['usuario_id']);
        self::assertSame('America/Manaus', $linhas[0]['fuso']);

        // O mesmo navegador em outro escritório: a linha de lá fica escondida pelo filtro, e mesmo assim muda de dono.
        $this->logarComTenant($client, $deLa, $outroEscritorio);
        $this->postar($client, '/agenda/push/inscrever', self::INSCRICAO);
        self::assertResponseIsSuccessful();
        $linhas = $this->em()->getConnection()->fetchAllAssociative('SELECT usuario_id, tenant_id FROM agenda_push_inscricao WHERE endpoint = :e', ['e' => self::INSCRICAO['endpoint']]);
        self::assertSame([['usuario_id' => $deLa->getId(), 'tenant_id' => $outroEscritorio->getId()]], $linhas);

        $this->postar($client, '/agenda/push/inscrever', ['endpoint' => 'http://inseguro/x'] + self::INSCRICAO);
        self::assertResponseStatusCodeSame(422);
        $this->logarComTenant($client, $semAgenda, $tenant);
        $this->postar($client, '/agenda/push/inscrever', self::INSCRICAO);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Cancelar apaga só a inscrição da própria pessoa')]
    public function testCancelar(): void
    {
        $client = $this->cliente();
        $tenant = $this->criarTenant();
        $dono = $this->criarUsuario($tenant, true);
        $outro = $this->criarUsuario($tenant, true);
        $this->logarComTenant($client, $dono, $tenant);
        $this->postar($client, '/agenda/push/inscrever', self::INSCRICAO);

        $this->logarComTenant($client, $outro, $tenant);
        $this->postar($client, '/agenda/push/cancelar', ['endpoint' => self::INSCRICAO['endpoint']]);
        self::assertSame(1, $this->contar());
        $this->logarComTenant($client, $dono, $tenant);
        $this->postar($client, '/agenda/push/cancelar', ['endpoint' => self::INSCRICAO['endpoint']]);
        self::assertSame(0, $this->contar());
    }

    private function contar(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_push_inscricao WHERE endpoint = :e', ['e' => self::INSCRICAO['endpoint']]);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });

        return $client;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode($corpo));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $t = (new Tenant())->setName('Tenant PSH ' . uniqid());
        $this->em()->persist($t);
        $this->em()->flush();

        return $t;
    }

    private function criarUsuario(Tenant $tenant, bool $gestor): User
    {
        $em = $this->em();
        $u = new User();
        $u->setEmail('psh_' . uniqid() . '@test.com');
        $u->setFullName('Usuário ' . uniqid());
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $em->persist($u);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $em->persist($role);
        $ut = new UserTenant($u, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $u;
    }
}
