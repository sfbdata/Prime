<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ContaConfirmacaoController;
use App\Auth\Entity\ConfirmacaoEmail;
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
 * "Reenviar o link de confirmação" (spec login-desenho-10): só logado, CSRF próprio, teto de um link a cada 2 minutos
 * por conta (no controller), conta confirmada não recebe nada, e a volta só para página deste mesmo host.
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
        $user = $this->entrar();

        $this->pedir('TOKEN_errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(0, $this->linksDa($user));
    }

    #[TestDox('Conta a confirmar: grava um link novo (sem gastar o lembrete) e envia o e-mail de confirmação com ele')]
    public function testContaAConfirmarRecebeOEmail(): void
    {
        $user = $this->entrar();

        $this->pedir();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertSame(['success' => [ContaConfirmacaoController::FLASH_ENVIADO]], $this->flashes());
        $enviados = $this->emailsEnviados();
        self::assertCount(1, $enviados);
        self::assertSame((string) $user->getEmail(), $enviados[0]->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/([a-f0-9]{64})#', (string) $enviados[0]->getHtmlBody(), $m));
        $link = $this->em()->getRepository(ConfirmacaoEmail::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($link);
        self::assertSame(ConfirmacaoEmail::hashDoToken($m[1]), $link->getTokenHash());
        self::assertFalse($link->isLembrete(), 'o botão não gasta o lembrete único da 5d');
    }

    #[TestDox('Segunda chamada em menos de 2 minutos: não reenvia e pede para aguardar')]
    public function testSegundaChamadaLogoDepoisNaoReenvia(): void
    {
        $user = $this->entrar();
        $this->pedir();
        self::assertCount(1, $this->emailsEnviados());

        $this->pedir();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertSame([], $this->emailsEnviados(), 'o registro do mailer é por requisição: nada saiu nesta');
        // O flash de sucesso da primeira chamada ainda não foi exibido (não houve página entre as duas).
        self::assertSame([ContaConfirmacaoController::FLASH_AGUARDE], $this->flashes()['warning'] ?? null);
        self::assertSame(1, $this->linksDa($user));
    }

    #[TestDox('Link emitido há mais de 2 minutos não segura o reenvio')]
    public function testEmissaoAntigaNaoSegura(): void
    {
        $user = $this->entrar();
        $antes = new \DateTimeImmutable('-3 minutes');
        $this->em()->persist(new ConfirmacaoEmail($user, str_repeat('b', 64), $antes->modify('+7 days'), criadoEm: $antes));
        $this->em()->flush();

        $this->pedir();

        self::assertCount(1, $this->emailsEnviados());
        self::assertSame(2, $this->linksDa($user));
    }

    /** Links já emitidos para a conta, com as idades dadas (ex.: '-3 hours'). */
    private function emitidos(User $user, string ...$idades): void
    {
        foreach ($idades as $i => $idade) {
            $criado = new \DateTimeImmutable($idade);
            $this->em()->persist(new ConfirmacaoEmail($user, hash('sha256', $idade . $i), $criado->modify('+7 days'), criadoEm: $criado));
        }
        $this->em()->flush();
    }

    #[TestDox('Teto diário: com 5 links nas últimas 24 h (o do cadastro conta), o 6º não sai')]
    public function testSextaEmissaoDoDiaNaoSai(): void
    {
        $user = $this->entrar();
        $this->emitidos($user, '-20 hours', '-10 hours', '-5 hours', '-1 hour', '-10 minutes');

        $this->pedir();

        self::assertResponseRedirects('/escritorio/convites');
        self::assertSame([], $this->emailsEnviados());
        self::assertSame(['warning' => [ContaConfirmacaoController::FLASH_TETO_DIARIO]], $this->flashes());
        self::assertSame(ContaConfirmacaoController::TETO_DIARIO, $this->linksDa($user));
    }

    #[TestDox('Teto diário: link de mais de 24 h atrás não conta')]
    public function testEmissaoDeOntemNaoConta(): void
    {
        $user = $this->entrar();
        $this->emitidos($user, '-25 hours', '-10 hours', '-5 hours', '-1 hour', '-10 minutes');

        $this->pedir();

        self::assertCount(1, $this->emailsEnviados());
        self::assertSame(['success' => [ContaConfirmacaoController::FLASH_ENVIADO]], $this->flashes());
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

        self::assertResponseRedirects('/');
    }
}
