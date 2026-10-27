<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Entity\AcessoConhecido;
use App\Auth\EventListener\RegistrarAcessoNoLoginListener;
use App\Auth\Repository\AcessoConhecidoRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Aviso de login novo (spec `login-aviso-novo.md`): o login pelo formulário em duas "máquinas" (User-Agent e IP
 * diferentes) manda 1 e-mail, só no segundo; a mesma máquina de novo não manda; o repositório grava e renova de forma
 * atômica e isola um usuário do outro.
 */
#[CoversClass(RegistrarAcessoNoLoginListener::class)]
#[CoversClass(AcessoConhecidoRepository::class)]
final class AvisoDeLoginNovoTest extends JusPrimeWebTestCase
{
    private const SENHA = 'segredo123';
    private const UA_A = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
    private const UA_B = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conta(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail('aviso_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA));
        $tenant = (new Tenant())->setName('Escritório ' . uniqid());
        $this->em()->persist($user);
        $this->em()->persist($tenant);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function entrarDe(KernelBrowser $client, User $user, string $userAgent, string $ip): void
    {
        $client->restart();
        $client->setServerParameter('HTTP_USER_AGENT', $userAgent);
        $client->setServerParameter('REMOTE_ADDR', $ip);
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = (string) $user->getEmail();
        $form['password'] = self::SENHA;
        $client->submit($form);
        self::assertSame('/login/entrando', parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private function acessos(User $user): int
    {
        return $this->em()->getRepository(AcessoConhecido::class)->count(['user' => $user]);
    }

    #[TestDox('Dois logins em máquinas diferentes: o primeiro só grava, o segundo envia 1 e-mail sem IP; repetir a máquina não envia')]
    public function testSegundaMaquinaAvisa(): void
    {
        $client = static::createClient();
        $user = $this->conta();

        $this->entrarDe($client, $user, self::UA_A, '200.10.20.5');
        self::assertEmailCount(0);
        self::assertSame(1, $this->acessos($user));

        $this->entrarDe($client, $user, self::UA_B, '187.55.66.77');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertSame((string) $user->getEmail(), $email?->getTo()[0]->getAddress());
        self::assertStringContainsString('Novo acesso à sua conta', (string) $email?->getSubject());
        $corpo = (string) $email?->getHtmlBody();
        self::assertStringContainsString('Firefox 121', $corpo);
        self::assertStringContainsString('Linux', $corpo);
        self::assertStringContainsString('/senha/esqueci', $corpo);
        self::assertStringNotContainsString('187.55.66', $corpo);
        self::assertSame(2, $this->acessos($user));

        $this->entrarDe($client, $user, self::UA_B, '187.55.66.99');
        self::assertEmailCount(0);
        self::assertSame(2, $this->acessos($user));
    }

    #[TestDox('Master desliga o aviso: grava a impressão nova e não envia e-mail')]
    public function testChaveOffNaoEnvia(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $this->entrarDe($client, $user, self::UA_A, '200.10.20.5');

        $this->em()->getConnection()->executeStatement('DELETE FROM configuracao_global');
        $configuracao = new \App\Master\Entity\ConfiguracaoGlobal();
        $configuracao->definirValores(['avisoLoginNovo' => 'off'], null, new \DateTimeImmutable());
        $this->em()->persist($configuracao);
        $this->em()->flush();

        $this->entrarDe($client, $user, self::UA_B, '187.55.66.77');

        self::assertEmailCount(0);
        self::assertSame(2, $this->acessos($user));
    }

    #[TestDox('Repositório: registrar grava, repetir só renova ultimo_em, e um usuário não enxerga os acessos do outro')]
    public function testRepositorio(): void
    {
        static::bootKernel();
        $repo = static::getContainer()->get(AcessoConhecidoRepository::class);
        $ana = $this->conta();
        $bia = $this->conta();
        $hash = str_repeat('a', 64);

        self::assertFalse($repo->usuarioTemAcessos($ana));
        self::assertTrue($repo->registrar($ana, $hash, new \DateTimeImmutable('2026-01-01 10:00:00')));
        self::assertFalse($repo->registrar($ana, $hash, new \DateTimeImmutable('2026-02-01 10:00:00')));
        self::assertTrue($repo->usuarioTemAcessos($ana));
        self::assertFalse($repo->usuarioTemAcessos($bia));
        self::assertTrue($repo->registrar($bia, $hash, new \DateTimeImmutable('2026-03-01 10:00:00')));

        $this->em()->clear();
        $linha = $this->em()->getRepository(AcessoConhecido::class)->findOneBy(['user' => $ana->getId()]);
        self::assertSame('2026-01-01 10:00:00', $linha?->getPrimeiroEm()->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-01 10:00:00', $linha?->getUltimoEm()->format('Y-m-d H:i:s'));

        // Retenção: o da Ana (visto em fevereiro) cai antes de abril; o da Bia (março) fica.
        $limite = new \DateTimeImmutable('2026-02-15');
        self::assertSame(1, $repo->contarNaoVistosDesde($limite));
        self::assertSame(1, $repo->purgarNaoVistosDesde($limite));
        self::assertFalse($repo->usuarioTemAcessos($ana));
        self::assertTrue($repo->usuarioTemAcessos($bia));
    }
}
