<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Command\PurgarEventosDeDesempenhoCommand;
use App\Master\Controller\MonitorEventoController;
use App\Master\Entity\EventoDeDesempenho;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /monitor/evento e a purga de retenção (spec master-desempenho D2 e §2.1). Armazém de CSRF fixo
 * ('TOKEN_<id>'), padrão dos testes do painel.
 */
#[CoversClass(MonitorEventoController::class)]
#[CoversClass(PurgarEventosDeDesempenhoCommand::class)]
final class MonitorEventoControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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
        $this->em->getRepository(EventoDeDesempenho::class)->apagarAntesDe();
        $this->user = UserFactory::createOne(['fullName' => 'Fulano', 'roles' => ['ROLE_USER'], 'isActive' => true])->_real();
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($this->user);
        $this->em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($this->user, $conta))->setTenantRole($perfil));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->user, $conta);
    }

    #[TestDox('grava o evento do usuário logado, só com o caminho da página, e responde 204')]
    public function testGrava(): void
    {
        $this->enviar(['tipo' => 'erro', 'msg' => 'TypeError: x', 'src' => 'app.js', 'pag' => '/pasta/1?cpf=123']);

        self::assertResponseStatusCodeSame(204);
        $eventos = $this->em->getRepository(EventoDeDesempenho::class)->findAll();
        self::assertCount(1, $eventos);
        self::assertSame('/pasta/1', $eventos[0]->getPagina());
        self::assertSame($this->user->getId(), $eventos[0]->getUsuario()?->getId());
    }

    #[TestDox('recusa: CSRF inválido 403, tipo login 422, corpo grande 413')]
    public function testRecusas(): void
    {
        $this->enviar(['tipo' => 'erro'], token: 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->enviar(['tipo' => 'login', 'ok' => false]);
        self::assertResponseStatusCodeSame(422);

        $this->enviar(['tipo' => 'erro', 'msg' => str_repeat('a', 2000)]);
        self::assertResponseStatusCodeSame(413);
        self::assertCount(0, $this->em->getRepository(EventoDeDesempenho::class)->findAll());
    }

    #[TestDox('anônimo não grava (redireciona para o login)')]
    public function testAnonimo(): void
    {
        $this->client->getCookieJar()->clear();
        $this->enviar(['tipo' => 'erro']);

        self::assertTrue($this->client->getResponse()->isRedirect() || $this->client->getResponse()->getStatusCode() === 401);
        self::assertCount(0, $this->em->getRepository(EventoDeDesempenho::class)->findAll());
    }

    #[TestDox('depois de 60 eventos no minuto responde 429 e não grava o excedente')]
    public function testLimite(): void
    {
        for ($i = 0; $i < MonitorEventoController::LIMITE_POR_MINUTO; ++$i) {
            $this->enviar(['tipo' => 'ping', 'ok' => true, 'ms' => 10]);
        }
        $this->enviar(['tipo' => 'ping', 'ok' => true, 'ms' => 10]);

        self::assertResponseStatusCodeSame(429);
        self::assertCount(MonitorEventoController::LIMITE_POR_MINUTO, $this->em->getRepository(EventoDeDesempenho::class)->findAll());
    }

    #[TestDox('app:desempenho:purgar apaga só o que passou de 90 dias')]
    public function testPurga(): void
    {
        $agora = new \DateTimeImmutable();
        $this->em->persist(new EventoDeDesempenho(TipoDeEventoDeDesempenho::Erro, $agora->modify('-91 days')));
        $this->em->persist(new EventoDeDesempenho(TipoDeEventoDeDesempenho::Erro, $agora->modify('-89 days')));
        $this->em->flush();

        $comando = new CommandTester((new Application(static::bootKernel()))->find('app:desempenho:purgar'));
        $comando->execute([]);

        self::assertStringContainsString('1 evento(s)', $comando->getDisplay());
        self::assertCount(1, $this->em->getRepository(EventoDeDesempenho::class)->findAll());
    }

    /** @param array<string, mixed> $corpo */
    private function enviar(array $corpo, string $token = 'TOKEN_monitor_evento'): void
    {
        $this->client->request('POST', '/monitor/evento', server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: json_encode($corpo, \JSON_THROW_ON_ERROR));
    }
}
