<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Functional;

use App\Agenda\Controller\AgendaNovaController;
use App\Agenda\Controller\GoogleAgendaController;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\UseCase\DispensarAvisoDoGoogleUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Janela que orienta a conectar o Google Agenda (spec docs/specs/agenda-aviso-google.md). Quem decide se ela abre é
 * o servidor (`data-google-aviso`); o desenho da janela é do JS (AgendaAvisoDoGoogleJsTest).
 */
#[CoversClass(AgendaNovaController::class)]
#[CoversClass(GoogleAgendaController::class)]
#[CoversClass(DispensarAvisoDoGoogleUseCase::class)]
final class AvisoDoGoogleAgendaTest extends JusPrimeWebTestCase
{
    private const DISPENSAR = '/agenda/google/aviso/dispensar';

    #[TestDox('Abre para quem não está conectado nem dispensou, com o endereço da dispensa')]
    public function testAbreParaQuemNaoConectou(): void
    {
        $client = $this->cliente();
        $this->entrar($client);

        $client->request('GET', '/agenda/classica');

        self::assertResponseIsSuccessful();
        self::assertSame('1', $this->atributo($client, 'data-google-aviso'));
        self::assertSame(self::DISPENSAR, $this->atributo($client, 'data-url-google-aviso-dispensar'));
    }

    #[TestDox('Não abre para quem já está conectado ao Google')]
    public function testNaoAbreConectado(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $this->conectado($eu, $tenant);

        $client->request('GET', '/agenda/classica');

        self::assertSame('0', $this->atributo($client, 'data-google-aviso'));
    }

    #[TestDox('Não abre com o servidor sem as credenciais do Google (indisponivel)')]
    public function testNaoAbreIndisponivel(): void
    {
        $client = $this->cliente(new GoogleAgendaApi(new MockHttpClient()));
        $this->entrar($client);

        $client->request('GET', '/agenda/classica');

        self::assertSame('indisponivel', $this->atributo($client, 'data-google'));
        self::assertSame('0', $this->atributo($client, 'data-google-aviso'));
    }

    #[TestDox('Sem CSRF o POST é recusado e nada é gravado; com CSRF grava true e a janela deixa de abrir')]
    public function testDispensarComCsrf(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);

        $client->request('POST', self::DISPENSAR, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->gravado($eu, $tenant), 'sem CSRF válido, nada é gravado');

        $client->request('POST', self::DISPENSAR, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $this->gravado($eu, $tenant));

        $client->request('GET', '/agenda/classica');
        self::assertSame('0', $this->atributo($client, 'data-google-aviso'));
    }

    #[TestDox('A dispensa é da pessoa e do escritório: o colega e a mesma pessoa em outro escritório ainda veem a janela')]
    public function testDispensaPorUsuarioEEscritorio(): void
    {
        $client = $this->cliente();
        [$eu, $tenant] = $this->entrar($client);
        $colega = $this->criarUsuario($tenant);
        $outro = $this->criarTenant();
        $this->vincular($eu, $outro);

        $client->request('POST', self::DISPENSAR, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $colega, $tenant);
        $client->request('GET', '/agenda/classica');
        self::assertSame('1', $this->atributo($client, 'data-google-aviso'), 'o colega não dispensou');
        self::assertFalse($this->gravado($colega, $tenant));

        $this->logarComTenant($client, $eu, $outro);
        $client->request('GET', '/agenda/classica');
        self::assertSame('1', $this->atributo($client, 'data-google-aviso'), 'no outro escritório a dispensa não vale');
    }

    #[TestDox('Sem acesso à Agenda o POST é recusado (403) e nada é gravado')]
    public function testSemAgendaRecusa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->entrar($client);
        $semAgenda = $this->criarUsuario($tenant, false);
        $this->logarComTenant($client, $semAgenda, $tenant);

        $client->request('POST', self::DISPENSAR, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->gravado($semAgenda, $tenant));
    }

    private function atributo(KernelBrowser $client, string $nome): ?string
    {
        return $client->getCrawler()->filter('#agPagina')->attr($nome);
    }

    private function gravado(User $u, Tenant $t): string|false
    {
        return $this->em()->getConnection()->fetchOne(
            'SELECT valor::text FROM preferencia_usuario WHERE user_id = :u AND tenant_id = :t AND chave = :c',
            ['u' => $u->getId(), 't' => $t->getId(), 'c' => DispensarAvisoDoGoogleUseCase::CHAVE],
        );
    }

    private function conectado(User $eu, Tenant $tenant): void
    {
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO');
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO agenda_google_conexao (tenant_id, usuario_id, refresh_token_cifrado, conta, fuso, conectada_em) VALUES (:t, :u, :c, 'pessoa@gmail.com', 'America/Sao_Paulo', NOW())",
            ['t' => $tenant->getId(), 'u' => $eu->getId(), 'c' => $cifrado],
        );
    }

    /** @return array{User, Tenant} */
    private function entrar(KernelBrowser $client): array
    {
        $tenant = $this->criarTenant();
        $eu = $this->criarUsuario($tenant);
        $this->logarComTenant($client, $eu, $tenant);

        return [$eu, $tenant];
    }

    private function cliente(?GoogleAgendaApi $api = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set(GoogleAgendaApi::class, $api ?? (new GoogleFalso())->api());
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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant AVG ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, bool $gestor = true): User
    {
        $u = new User();
        $u->setEmail('avg_' . uniqid() . '@test.com');
        $u->setFullName('Usuário ' . uniqid());
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();
        $this->vincular($u, $tenant, $gestor);

        return $u;
    }

    private function vincular(User $u, Tenant $tenant, bool $gestor = true): void
    {
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($gestor);
        $this->em()->persist($role);
        $ut = new UserTenant($u, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();
    }
}
