<?php

declare(strict_types=1);

namespace App\Tests\Security\Functional;

use App\Entity\Auth\User;
use App\Security\TokenDoManterConectado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Auditoria 3.29: o cookie "Manter conectado" era só assinado (usuário, validade e hash da senha) e o servidor não
 * guardava nada. Sair apagava o cookie do navegador, mas uma cópia dele seguia entrando por 30 dias. Agora o cookie é
 * uma série na tabela `rememberme_token` (token persistente do Symfony): sair apaga a série e a cópia não entra mais.
 */
#[CoversClass(TokenDoManterConectado::class)]
final class SairRevogaManterConectadoTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-forte-123';

    #[TestDox('Depois de sair, a cópia do cookie "Manter conectado" não autentica mais')]
    public function testSairRevogaOCookie(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario();
        $copia = $this->entrar($client, $user, manter: true);
        self::assertNotNull($copia, 'o login com "manter conectado" emite o cookie');

        $this->esquecerSessao($client);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('antes de sair, o cookie sozinho autentica');

        $client->request('GET', '/logout?_csrf_token=csrf-token', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame(0, $this->seriesDe($user), 'sair apaga a série do servidor');

        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($copia);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login', null, 'a cópia guardada antes de sair não entra');
    }

    #[TestDox('Com "Manter conectado", o login grava uma série; sem ele, nenhuma')]
    public function testSerieSoComManterConectado(): void
    {
        $client = static::createClient();
        $sem = $this->criarUsuario();
        self::assertNull($this->entrar($client, $sem, manter: false));
        self::assertSame(0, $this->seriesDe($sem));

        $client->getCookieJar()->clear();
        $com = $this->criarUsuario();
        self::assertNotNull($this->entrar($client, $com, manter: true));
        self::assertSame(1, $this->seriesDe($com));
    }

    #[TestDox('O banco não guarda o valor do cookie: um cookie montado com o que está na tabela não entra, a série acaba e o aviso vai ao log sem e-mail nem valor')]
    public function testCookieMontadoPeloBancoNaoEntra(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $log = new TestHandler();
        static::getContainer()->get('monolog.logger')->pushHandler($log);
        $user = $this->criarUsuario();
        $real = $this->entrar($client, $user, manter: true);
        self::assertNotNull($real);
        $detalhes = RememberMeDetails::fromRawCookie((string) $real->getValue());
        [$serie, $valor] = explode(':', $detalhes->getValue(), 2);
        $guardado = (string) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT value FROM rememberme_token WHERE series = ?', [$serie]);
        self::assertNotSame($valor, $guardado, 'o valor do cookie não vai para o banco');

        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie('REMEMBERME', $detalhes->withValue($serie . ':' . $guardado)->toString()));
        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login', null, 'quem só leu o banco não entra');
        self::assertSame(0, $this->seriesDe($user), 'o valor errado numa série que existe encerra a série');

        $avisos = array_values(array_filter($log->getRecords(), static fn (LogRecord $r): bool => str_starts_with($r->message, 'Manter conectado:')));
        self::assertCount(1, $avisos, 'o roubo de cookie fica no log');
        self::assertSame(Level::Warning, $avisos[0]->level);
        $registro = json_encode([$avisos[0]->message, $avisos[0]->context, $avisos[0]->extra], \JSON_THROW_ON_ERROR);
        foreach ([(string) $user->getEmail(), $serie, $valor, $guardado] as $proibido) {
            self::assertStringNotContainsString($proibido, $registro, 'o aviso não leva e-mail, série nem valor do token');
        }
    }

    #[TestDox('Série sem uso há mais de 30 dias é apagada quando nasce outra')]
    public function testSerieVencidaEApagada(): void
    {
        $client = static::createClient();
        $conexao = static::getContainer()->get(Connection::class);
        $conexao->insert('rememberme_token', [
            'series' => 'serie-vencida', 'value' => 'x', 'lastUsed' => (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'),
            'class' => '', 'username' => 'antiga@adv.com',
        ]);

        self::assertNotNull($this->entrar($client, $this->criarUsuario(), manter: true));

        self::assertSame(0, (int) $conexao->fetchOne("SELECT COUNT(*) FROM rememberme_token WHERE series = 'serie-vencida'"));
    }

    /** Entra pelo formulário e devolve o cookie REMEMBERME (null quando não foi emitido). */
    private function entrar(KernelBrowser $client, User $user, bool $manter): ?Cookie
    {
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = (string) $user->getEmail();
        $form['password'] = self::SENHA;
        if ($manter) {
            $form['_remember_me']->tick();
        }
        $client->submit($form);

        return $client->getCookieJar()->get('REMEMBERME');
    }

    /** Expira o cookie de sessão sem tocar no "manter conectado". */
    private function esquecerSessao(KernelBrowser $client): void
    {
        foreach ($client->getCookieJar()->all() as $cookie) {
            if ($cookie->getName() !== 'REMEMBERME') {
                $client->getCookieJar()->expire($cookie->getName());
            }
        }
    }

    private function seriesDe(User $user): int
    {
        return (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM rememberme_token WHERE username = ?', [$user->getEmail()]);
    }

    private function criarUsuario(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('manter_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
