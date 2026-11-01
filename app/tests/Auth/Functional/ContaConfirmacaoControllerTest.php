<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ContaConfirmacaoController;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\UseCase\ReenviarConfirmacaoDeEmailUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Reenviar o link de confirmação" logado (spec login-desenho-10): só logado, CSRF próprio, conta confirmada não
 * recebe nada, e a volta só para página deste mesmo host.
 *
 * Desde a spec `cadastro-exige-confirmacao.md`, conta a confirmar não fica logada: a sessão dela termina no
 * `SessaoDeContaAConfirmarListener` antes de chegar aqui. Os tetos (agora no `TetoDoReenvioDeConfirmacao`, com a
 * mesma regra) se provam no `TetoDoReenvioDeConfirmacaoTest` e no `ReenvioPublicoDeConfirmacaoTest`.
 */
#[CoversClass(ContaConfirmacaoController::class)]
#[CoversClass(ReenviarConfirmacaoDeEmailUseCase::class)]
final class ContaConfirmacaoControllerTest extends JusPrimeWebTestCase
{
    private const URL = '/conta/confirmacao/reenviar';
    private const CSRF_VALIDO = 'TOKEN_' . ContaConfirmacaoController::CSRF;
    private const ORIGEM = 'http://localhost/escritorio/convites';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function entrar(bool $aConfirmar = true): User
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ' . uniqid());
        $user = new User();
        $user->setEmail('conta_' . uniqid() . '@adv.com');
        $user->setFullName('Ana Advogada');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        if ($aConfirmar) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($tenant);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        return $user;
    }

    private function pedir(string $token = self::CSRF_VALIDO, string $referer = self::ORIGEM): void
    {
        $this->client->request('POST', self::URL, ['_token' => $token], [], ['HTTP_REFERER' => $referer]);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peekAll();
    }

    private function linksDa(User $user): int
    {
        return $this->em()->getRepository(ConfirmacaoEmail::class)->count(['user' => $user->getId()]);
    }

    /** @return list<Email> */
    private function emailsEnviados(): array
    {
        $enviados = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents() as $evento) {
            if (!$evento->isQueued() && $evento->getMessage() instanceof Email) {
                $enviados[] = $evento->getMessage();
            }
        }

        return $enviados;
    }

    #[TestDox('Sem login: vai para o login')]
    public function testSemLoginVaiParaOLogin(): void
    {
        $this->client->request('POST', self::URL, ['_token' => self::CSRF_VALIDO]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('CSRF inválido: 403, nada gravado nem enviado')]
    public function testCsrfInvalido(): void
    {
        $user = $this->entrar(aConfirmar: false);

        $this->pedir('TOKEN_errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
    }

    #[TestDox('Conta a confirmar logada (sessão de antes da regra): a sessão termina, vai ao login e nada é enviado')]
    public function testContaAConfirmarTemASessaoEncerrada(): void
    {
        $user = $this->entrar();

        $this->pedir();

        self::assertResponseRedirects('/login');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        self::assertSame($user->getId(), $this->client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO)['id'] ?? null);
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
    }

    #[TestDox('Conta já confirmada: não grava nem envia, e avisa que já está confirmada')]
    public function testContaConfirmadaNaoReenvia(): void
    {
        $user = $this->entrar(aConfirmar: false);

        $this->pedir();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
        self::assertSame(['info' => [ContaConfirmacaoController::FLASH_JA_CONFIRMADO]], $this->flashes());
    }

    /** @return iterable<string, array{string}> */
    public static function origensDeFora(): iterable
    {
        yield 'outro host' => ['https://evil.example/escritorio/convites'];
        yield 'caminho com duas barras' => ['http://localhost//evil.example/x'];
        yield 'sem referer' => [''];
        yield 'esquema estranho' => ['javascript://localhost/%0aalert(1)'];
    }

    #[DataProvider('origensDeFora')]
    #[TestDox('Volta só para página deste host; fora disso, para a página inicial')]
    public function testVoltaSegura(string $referer): void
    {
        $this->entrar(aConfirmar: false);

        $this->pedir(referer: $referer);

        self::assertResponseRedirects('/inicio');
    }
}
