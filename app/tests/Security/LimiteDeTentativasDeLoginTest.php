<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Security\LimitadorDeTentativasDeLogin;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O login respeita o limite do Master (spec `login-config-tentativas.md`): senha errada N vezes e a (N+1)ª recebe a
 * resposta de excesso; sem configuração, vale 5; login certo zera o contador do usuário+IP.
 */
#[CoversClass(LimitadorDeTentativasDeLogin::class)]
final class LimiteDeTentativasDeLoginTest extends JusPrimeWebTestCase
{
    private const SENHA = 'segredo123';
    private const EXCESSO = 'Muitas tentativas de login';

    private function conta(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('limite_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function tentativas(int $n): void
    {
        $repositorio = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $repositorio->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirValores(['tentativas' => (string) $n], null, new \DateTimeImmutable());
        $repositorio->salvar($configuracao, true);
    }

    /**
     * Envia o formulário e diz se a entrada foi aceita. A tela mostra a mesma mensagem genérica para senha errada e
     * para excesso (não revela o bloqueio), então "barrado" se prova pela senha certa que não entra.
     */
    private function entrou(KernelBrowser $client, string $email, string $senha): bool
    {
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = $email;
        $form['password'] = $senha;
        $client->submit($form);

        $entrou = $client->getResponse()->isRedirect() && parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH) === '/login/entrando';
        if ($entrou) {
            $client->getCookieJar()->clear();
        }

        return $entrou;
    }

    private function errar(KernelBrowser $client, User $user, int $vezes): void
    {
        for ($i = 0; $i < $vezes; ++$i) {
            self::assertFalse($this->entrou($client, (string) $user->getEmail(), 'errada'));
        }
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        // O pool de teste (ArrayAdapter) é zerado a cada requisição; este storage sobrevive, como o cache em produção.
        static::getContainer()->set('limiter.storage.login_tentativas', new InMemoryStorage());

        return $client;
    }

    #[TestDox('Com tentativas = 3: depois de 3 senhas erradas, nem a senha certa entra (4ª barrada)')]
    public function testConfiguracaoTresBloqueiaNaQuarta(): void
    {
        $client = $this->cliente();
        $this->tentativas(3);
        $user = $this->conta();

        $this->errar($client, $user, 3);

        self::assertFalse($this->entrou($client, (string) $user->getEmail(), self::SENHA), 'a 4ª tentativa é barrada');
    }

    #[TestDox('Com tentativas = 3: depois de 2 senhas erradas, a certa (3ª tentativa) ainda entra')]
    public function testConfiguracaoTresDeixaATerceira(): void
    {
        $client = $this->cliente();
        $this->tentativas(3);
        $user = $this->conta();

        $this->errar($client, $user, 2);

        self::assertTrue($this->entrou($client, (string) $user->getEmail(), self::SENHA));
    }

    #[TestDox('Sem configuração gravada vale o padrão 5: a 5ª tentativa (certa) entra depois de 4 erradas; a 6ª é barrada depois de 5')]
    public function testPadraoCinco(): void
    {
        $client = $this->cliente();
        $quase = $this->conta();
        $this->errar($client, $quase, 4);
        self::assertTrue($this->entrou($client, (string) $quase->getEmail(), self::SENHA), 'a 5ª entra');

        $esgotada = $this->conta();
        $this->errar($client, $esgotada, 5);
        self::assertFalse($this->entrou($client, (string) $esgotada->getEmail(), self::SENHA), 'a 6ª é barrada');
    }

    #[TestDox('Login certo zera o contador do usuário+IP: erra, acerta, e ainda pode errar de novo sem ser barrado cedo')]
    public function testLoginCertoZeraOContador(): void
    {
        $client = $this->cliente();
        $this->tentativas(3);
        $user = $this->conta();

        $this->errar($client, $user, 2);
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), self::SENHA), 'a 3ª, certa, entra e zera');

        // Sem o reset a contagem seria 3 + 2 e a próxima já estaria barrada; com ele, é a 3ª da janela nova.
        $this->errar($client, $user, 2);
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), self::SENHA));
    }

    #[TestDox('Teto da conta (auditoria 3.28): falhas de IPs diferentes somam pelo e-mail e, no teto, nem a senha certa entra')]
    public function testTetoDaContaSomaIpsDiferentes(): void
    {
        $client = $this->cliente();
        $this->tentativas(1);
        $user = $this->conta();

        // Limite 1: o usuário+IP barra na 2ª do mesmo IP; cada falha vem de um IP novo, e o teto da conta é 10.
        for ($i = 1; $i <= 10 * 1; ++$i) {
            $client->setServerParameter('REMOTE_ADDR', '10.9.0.' . $i);
            self::assertFalse($this->entrou($client, strtoupper((string) $user->getEmail()), 'errada'));
        }

        $client->setServerParameter('REMOTE_ADDR', '10.9.1.1');
        self::assertFalse($this->entrou($client, (string) $user->getEmail(), self::SENHA), 'no teto, a senha certa de um IP novo é barrada');
    }

    #[TestDox('Abaixo do teto da conta, a senha certa de outro IP entra e zera o contador da conta')]
    public function testAbaixoDoTetoEntraEZera(): void
    {
        $client = $this->cliente();
        $this->tentativas(1);
        $user = $this->conta();

        for ($i = 1; $i <= 9; ++$i) {
            $client->setServerParameter('REMOTE_ADDR', '10.8.0.' . $i);
            self::assertFalse($this->entrou($client, (string) $user->getEmail(), 'errada'));
        }
        $client->setServerParameter('REMOTE_ADDR', '10.8.1.1');
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), self::SENHA), 'a 10ª, certa, entra');

        // Sem o reset da conta, a próxima falha fecharia o teto (9 + 1) e a senha certa seguinte seria barrada.
        $client->setServerParameter('REMOTE_ADDR', '10.8.2.1');
        self::assertFalse($this->entrou($client, (string) $user->getEmail(), 'errada'));
        $client->setServerParameter('REMOTE_ADDR', '10.8.3.1');
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), self::SENHA));
    }

    #[TestDox('No teto da conta, a troca de senha pelo link do e-mail abre o login por senha de novo')]
    public function testRecuperacaoPeloLinkAbreOTeto(): void
    {
        $client = $this->cliente();
        $this->tentativas(1);
        $user = $this->conta();
        for ($i = 1; $i <= 10; ++$i) {
            $client->setServerParameter('REMOTE_ADDR', '10.7.0.' . $i);
            $this->entrou($client, (string) $user->getEmail(), 'errada');
        }

        $token = bin2hex(random_bytes(32));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new RedefinicaoSenha(
            user: $em->find(User::class, $user->getId()),
            tokenHash: RedefinicaoSenha::hashDoToken($token),
            expiraEm: new \DateTimeImmutable('+1 hour'),
            ip: '10.7.9.9',
        ));
        $em->flush();
        $form = $client->request('GET', '/senha/redefinir/' . $token)->selectButton('Salvar nova senha')->form();
        $form['redefinir_senha[senha][first]'] = 'senha-nova-456';
        $form['redefinir_senha[senha][second]'] = 'senha-nova-456';
        $client->submit($form);
        self::assertResponseRedirects('/login');

        $client->setServerParameter('REMOTE_ADDR', '10.7.1.1');
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), 'senha-nova-456'));
    }

    #[TestDox('No teto da conta, a troca de senha pelo código do e-mail (POST /senha/codigo/confirmar) abre o login por senha de novo')]
    public function testRecuperacaoPeloCodigoAbreOTeto(): void
    {
        $client = $this->cliente();
        $this->tentativas(1);
        $user = $this->conta();
        $this->fecharTeto($client, (string) $user->getEmail(), '10.6.0.');
        $client->setServerParameter('REMOTE_ADDR', '10.6.1.1');
        self::assertFalse($this->entrou($client, (string) $user->getEmail(), self::SENHA), 'o teto está fechado');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $segredo = (string) static::getContainer()->getParameter('kernel.secret');
        $em->persist(new RedefinicaoSenha(
            user: $em->find(User::class, $user->getId()),
            tokenHash: SolicitarCodigoSenhaUseCase::hashDoCodigo((int) $user->getId(), '123456', $segredo),
            expiraEm: new \DateTimeImmutable('+15 minutes'),
            ip: '10.6.9.9',
        ));
        $em->flush();
        $token = (string) $client->request('GET', '/login')->filter('[data-token-senha]')->attr('data-token-senha');
        $client->request('POST', '/senha/codigo/confirmar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
            'email' => (string) $user->getEmail(), 'codigo' => '123456', 'senha' => 'senha-nova-456', 'confirmacao' => 'senha-nova-456', '_token' => $token,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->setServerParameter('REMOTE_ADDR', '10.6.2.1');
        self::assertTrue($this->entrou($client, (string) $user->getEmail(), 'senha-nova-456'));
    }

    #[TestDox('No teto, e-mail que não existe recebe a mesma resposta que a conta real com a senha certa (não revela quem tem conta)')]
    public function testTetoNaoRevelaSeAContaExiste(): void
    {
        $client = $this->cliente();
        $this->tentativas(1);
        $user = $this->conta();
        $inexistente = 'ninguem_' . uniqid() . '@adv.com';
        $this->fecharTeto($client, (string) $user->getEmail(), '10.5.0.');
        $this->fecharTeto($client, $inexistente, '10.5.1.');

        $client->setServerParameter('REMOTE_ADDR', '10.5.2.1');
        $real = $this->respostaDoLogin($client, (string) $user->getEmail(), self::SENHA);
        $client->setServerParameter('REMOTE_ADDR', '10.5.2.2');
        $falso = $this->respostaDoLogin($client, $inexistente, self::SENHA);

        self::assertStringContainsString(self::EXCESSO, $real[1]);
        self::assertSame($real, $falso, 'mesmo destino e mesmo aviso');
    }

    /** Dez falhas, cada uma de um IP novo (o usuário+IP com limite 1 não barra antes): fecha o teto da conta. */
    private function fecharTeto(KernelBrowser $client, string $email, string $prefixoIp): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $client->setServerParameter('REMOTE_ADDR', $prefixoIp . $i);
            self::assertFalse($this->entrou($client, $email, 'errada'));
        }
    }

    /** @return array{string, string} o destino do redirecionamento e o aviso da tela de login */
    private function respostaDoLogin(KernelBrowser $client, string $email, string $senha): array
    {
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = $email;
        $form['password'] = $senha;
        $client->submit($form);
        $destino = (string) $client->getResponse()->headers->get('Location');

        return [$destino, trim($client->followRedirect()->filter('#lgErro')->text())];
    }
}
