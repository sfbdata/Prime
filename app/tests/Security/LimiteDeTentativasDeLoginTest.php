<?php

declare(strict_types=1);

namespace App\Tests\Security;

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
}
