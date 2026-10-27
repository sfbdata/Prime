<?php

declare(strict_types=1);

namespace App\Tests\Security\Functional;

use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Security\ExpiracaoDaSessaoListener;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

/** Expiração por inatividade vista de fora: sessão envelhecida cai no /login com o aviso (spec login-sessao-expiracao). */
#[CoversClass(ExpiracaoDaSessaoListener::class)]
final class ExpiracaoDaSessaoTest extends JusPrimeWebTestCase
{
    private const AVISO = 'Sua sessão expirou por inatividade. Entre novamente.';

    #[TestDox('Dentro do prazo, a página abre normalmente')]
    public function testDentroDoPrazo(): void
    {
        $client = $this->logado(10 * 60);

        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'), 'sessão de 10 min com padrão de 24 não expira');
    }

    #[TestDox('Inativa além do padrão: cai no /login com o aviso e a sessão acaba')]
    public function testSessaoEnvelhecidaCaiNoLogin(): void
    {
        $client = $this->logado(40 * 60);

        $client->request('GET', '/master');
        self::assertResponseRedirects('/login');
        $crawler = $client->followRedirect();

        self::assertStringContainsString(self::AVISO, $crawler->filter('.lg-flash--error')->text());
        $client->request('GET', '/master');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Pedido de dados (XHR) com a sessão vencida recebe 401 em JSON')]
    public function testXhrRecebe401(): void
    {
        $client = $this->logado(40 * 60);

        $client->request('GET', '/master', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401);
        self::assertJson((string) $client->getResponse()->getContent());
    }

    #[TestDox('Cookie de sessão velho em rota pública (/convite, /senha) não vai ao login')]
    public function testRotaPublicaNaoExpira(): void
    {
        $client = $this->logado(40 * 60);

        foreach (['/convite/token-qualquer', '/senha/esqueci'] as $url) {
            $client->request('GET', $url);
            self::assertFalse($client->getResponse()->isRedirect('/login'), $url);
        }
    }

    #[TestDox('Cada requisição renova o carimbo: depois de um acesso dentro do prazo, o último uso é agora')]
    public function testRequisicaoRenova(): void
    {
        $client = $this->logado(20 * 60);

        $client->request('GET', '/master');
        self::assertFalse($client->getResponse()->isRedirect('/login'));

        $ultimo = $client->getSession()->getMetadataBag()->getLastUsed();
        self::assertLessThan(10, time() - $ultimo, 'o carimbo gravado pela requisição é recente, não os 20 min antigos');
    }

    #[TestDox('O prazo gravado no Master vale: com sessao=480, 40 min de pausa não expira')]
    public function testConfiguracaoDoMasterVale(): void
    {
        $client = $this->logado(40 * 60);
        $repositorio = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $repositorio->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirValores(['sessao' => '480'], null, new \DateTimeImmutable());
        $repositorio->salvar($configuracao, true);

        $client->request('GET', '/master');

        self::assertFalse($client->getResponse()->isRedirect('/login'));
    }

    /** Entra como SuperAdmin e recua o carimbo de último uso da sessão em `$segundosParado`. */
    private function logado(int $segundosParado): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('expira_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Expira');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $sessao = $client->getSession();
        $meta = $sessao->getMetadataBag();
        self::assertInstanceOf(MetadataBag::class, $meta);
        $propriedade = new \ReflectionProperty(MetadataBag::class, 'meta');
        $dados = $propriedade->getValue($meta);
        $dados[MetadataBag::UPDATED] = time() - $segundosParado;
        $propriedade->setValue($meta, $dados);
        $sessao->save();

        return $client;
    }
}
