<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroController;
use App\Auth\Controller\CadastroPeloFacebookController;
use App\Auth\Controller\CadastroPeloGoogleController;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\CadastroPeloGoogle;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Tests\Auth\FacebookLoginFalso;
use App\Tests\Auth\GoogleLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Política de cadastro do Master (`cadastro` = on | convite | off; spec `login-config-cadastro.md`) vista de fora:
 * as três portas públicas respondem 403 neutro, a tela de entrada esconde o "Criar conta" e mostra o aviso, o login de
 * quem já tem conta segue, e o convite de escritório e da plataforma obedecem a regra de cada política.
 */
#[CoversClass(CadastroController::class)]
#[CoversClass(CadastroPeloGoogleController::class)]
#[CoversClass(CadastroPeloFacebookController::class)]
final class CadastroPoliticaTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-de-teste-123';
    private const AVISO_CONVITE = 'O cadastro está disponível só por convite.';
    private const AVISO_FECHADO = 'O cadastro está fechado no momento.';

    private GoogleLoginFalso $google;
    private string $hrefDoFacebook = '';
    private FacebookLoginFalso $facebook;

    /** @return iterable<string, array{string, string}> */
    public static function politicas(): iterable
    {
        yield 'só por convite' => ['convite', self::AVISO_CONVITE];
        yield 'fechado' => ['off', self::AVISO_FECHADO];
    }

    #[TestDox('Cadastro aberto (sem linha gravada): o link "Criar conta" e o passo do cadastro seguem como antes')]
    public function testAbertoNaoMudaATela(): void
    {
        $client = $this->cliente();

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        self::assertCount(1, $crawler->filter('.lg-semconta > a#lgCriar'));
        self::assertCount(1, $crawler->filter('#lgMdForm > .lg-md__passo[data-passo="cad"]'));
        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdGoogle'));
        self::assertSame('', (string) $crawler->filter('#lgRaiz')->attr('data-cadastro-bloqueado'));
        self::assertSame(0, $crawler->filter('#lgErro:not([hidden])')->count());
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: a tela de entrada não oferece "Criar conta" nem botões sociais do cadastro; o cartão de login fica')]
    public function testTelaEscondeOCadastro(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);

        $crawler = $client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('#lgCriar, .lg-semconta'));
        self::assertCount(0, $crawler->filter('#lgCdGoogle, #lgCdFacebook, #lgCdSoc'));
        self::assertSame('1', $crawler->filter('#lgRaiz')->attr('data-cadastro-bloqueado'));
        self::assertCount(1, $crawler->filter('form input[name="email"]'));
        self::assertCount(1, $crawler->filter('#lgGoogle'), 'o login pelo Google segue');
        self::assertStringNotContainsString($aviso, (string) $crawler->filter('#lgErro')->text(), 'sem pedir o cadastro, nenhum aviso');
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: ?abrir=cadastro, ?ind= e /cadastro caem no login com o aviso da política')]
    public function testAbrirCadastroMostraOAviso(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);

        foreach (['/login?abrir=cadastro', '/login?ind=BJ-QWER2345'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertSame($aviso, trim($crawler->filter('#lgErro:not([hidden])')->text()), $url);
        }

        $client->request('GET', '/cadastro');
        self::assertResponseRedirects('/login?abrir=cadastro');
        $crawler = $client->followRedirect();
        self::assertSame($aviso, trim($crawler->filter('#lgErro:not([hidden])')->text()));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: o link curto /i/{codigo} leva ao login com o aviso')]
    public function testLinkCurtoDeIndicacao(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);

        $client->request('GET', '/i/QWER2345');
        $client->followRedirect();
        $crawler = $client->followRedirect();

        self::assertSame($aviso, trim($crawler->filter('#lgErro:not([hidden])')->text()));
        self::assertCount(0, $crawler->filter('#lgCriar'));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: POST /cadastro/criar responde 403 com a mensagem da política, sem criar nada e sem dizer se o e-mail existe')]
    public function testCadastroPorEmailRecusa(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);
        $existente = $this->criarUsuario();
        $novo = 'novo_' . uniqid() . '@adv.com';
        $token = $this->token($client);

        $respostas = [];
        foreach ([$novo, (string) $existente->getEmail()] as $email) {
            $client->jsonRequest('POST', '/cadastro/criar', ['nome' => 'Ana Souza', 'escritorio' => 'Souza Advogados', 'email' => $email, 'genero' => 'feminino', 'aceite' => true, '_token' => $token]);
            self::assertResponseStatusCodeSame(403);
            $respostas[] = (string) $client->getResponse()->getContent();
        }

        self::assertSame($respostas[0], $respostas[1], 'e-mail novo e e-mail com conta recebem a mesma resposta');
        self::assertSame(['erro' => $aviso], json_decode($respostas[0], true));
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $novo]));
        self::assertNull($this->em()->getRepository(Tenant::class)->findOneBy(['name' => 'Souza Advogados']));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: POST /cadastro/google/criar responde 403, não cria a conta e a identidade sai da sessão')]
    public function testCadastroPeloGoogleRecusa(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->voltarDoGoogle($client);
        self::assertResponseRedirects('/login?abrir=cadastro');
        $this->politica($politica);
        $token = $this->token($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', ['nome' => 'Ana Google Souza', 'escritorio' => 'Google Advogados', 'genero' => 'feminino', 'aceite' => true, '_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => $aviso], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $this->google->email]));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: POST /cadastro/facebook/criar responde 403, não cria a conta e a identidade sai da sessão')]
    public function testCadastroPeloFacebookRecusa(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->voltarDoFacebook($client);
        self::assertResponseRedirects('/login?abrir=cadastro');
        $this->politica($politica);
        $token = $this->token($client);
        $email = 'fb_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', ['nome' => 'Ana Facebook Souza', 'escritorio' => 'Face Advogados', 'email' => $email, 'genero' => 'feminino', 'aceite' => true, '_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => $aviso], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: Google e Facebook sem conta voltam ao login com o aviso, sem guardar a identidade nem abrir o cadastro')]
    public function testProvedorSemContaVoltaAoLoginComAviso(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);

        $this->voltarDoGoogle($client);
        self::assertResponseRedirects('/login');
        self::assertSame($aviso, trim($client->followRedirect()->filter('#lgErro:not([hidden])')->text()));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO));

        $this->voltarDoFacebook($client);
        self::assertResponseRedirects('/login');
        self::assertSame($aviso, trim($client->followRedirect()->filter('#lgErro:not([hidden])')->text()));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: o login de conta existente com senha não muda')]
    public function testLoginComSenhaSegue(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);
        $usuario = $this->criarUsuario();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->filter('form[method="post"]')->form(['email' => $usuario->getEmail(), 'password' => self::SENHA]));

        self::assertResponseRedirects();
        self::assertNotSame('/login', $client->getResponse()->headers->get('Location'));
    }

    #[DataProvider('politicas')]
    #[TestDox('Política $politica: o login de conta existente pelo Google não muda')]
    public function testLoginPeloGoogleSegue(string $politica, string $aviso): void
    {
        $client = $this->cliente();
        $this->politica($politica);
        $usuario = $this->criarUsuario();
        $usuario->ligarAoGoogle($this->google->sub);
        $this->google->email = (string) $usuario->getEmail();
        $this->em()->flush();

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login?', (string) $client->getResponse()->headers->get('Location'));
        self::assertNotSame('/login', $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Mudar a configuração vale na requisição seguinte: aberto, fechado e de volta a aberto')]
    public function testMudancaValeNaRequisicaoSeguinte(): void
    {
        $client = $this->cliente();
        self::assertCount(1, $client->request('GET', '/login')->filter('#lgCriar'));

        $this->politica('off');
        self::assertCount(0, $client->request('GET', '/login')->filter('#lgCriar'));

        $this->politica('on');
        self::assertCount(1, $client->request('GET', '/login')->filter('#lgCriar'));
    }

    #[TestDox('Só por convite: o convite de escritório cria a conta; fechado: o formulário volta com o aviso e nada nasce')]
    public function testConviteDeEscritorio(): void
    {
        $client = $this->cliente();
        $this->politica('convite');
        [$token, $email] = $this->convite(Invitation::TIPO_ESCRITORIO);
        $this->aceitarSemConta($client, $token);
        self::assertResponseRedirects('/login');
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));

        $this->politica('off');
        [$token, $email] = $this->convite(Invitation::TIPO_ESCRITORIO);
        $this->aceitarSemConta($client, $token);
        self::assertResponseRedirects('/convite/' . $token);
        self::assertStringContainsString(self::AVISO_FECHADO, $client->followRedirect()->text());
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('Fechado: o convite da PLATAFORMA (emitido pelo Master) ainda cria a conta')]
    public function testConviteDaPlataformaSegueComOCadastroFechado(): void
    {
        $client = $this->cliente();
        $this->politica('off');
        [$token, $email] = $this->convite(Invitation::TIPO_PLATAFORMA);

        $crawler = $client->request('GET', '/convite/' . $token);
        $form = $crawler->filter('form[action="/convite/' . $token . '/plataforma"]')->form([
            'full_name' => 'Dra. Convidada',
            'senha' => self::SENHA,
            'oab_numero' => '123456',
            'oab_uf' => 'SP',
            'genero' => 'feminino',
            'aceite_termos' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/login');
        self::assertNotNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    private function aceitarSemConta(KernelBrowser $client, string $token): void
    {
        $crawler = $client->request('GET', '/convite/' . $token);
        $form = $crawler->filter('form[action="/convite/' . $token . '/criar-conta"]')->form([
            'full_name' => 'Dr. Convidado',
            'senha' => self::SENHA,
            'genero' => 'masculino',
            'aceite_termos' => '1',
        ]);
        $client->submit($form);
    }

    /** @return array{string, string} token em claro e e-mail */
    private function convite(string $tipo): array
    {
        $token = bin2hex(random_bytes(32));
        $email = 'convidado_' . uniqid() . '@adv.com';
        $convite = new Invitation(email: $email, tokenHash: Invitation::hashDoToken($token), type: $tipo, expiresAt: new \DateTimeImmutable('+7 days'));
        if ($tipo === Invitation::TIPO_ESCRITORIO) {
            $escritorio = (new Tenant())->setName('Escritório que convidou');
            $this->em()->persist($escritorio);
            $convite->setTenant($escritorio);
        }
        $this->em()->persist($convite);
        $this->em()->flush();

        return [$token, $email];
    }

    private function politica(string $valor): void
    {
        $repositorio = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $repositorio->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirValores(['cadastro' => $valor], null, new \DateTimeImmutable());
        $repositorio->salvar($configuracao, true);
    }

    private function criarUsuario(): User
    {
        $user = new User();
        $user->setEmail('politica_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Existente');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::SENHA));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/login')->filter('#lgRaiz')->attr('data-token-cadastro');
    }

    private function voltarDoGoogle(KernelBrowser $client): void
    {
        $client->request('GET', '/login/google');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $this->google->nonce = $q['nonce'];
        $client->request('GET', '/login/google/callback?code=abc&state=' . $q['state']);
        $this->google->nonce = null;
    }

    private function voltarDoFacebook(KernelBrowser $client): void
    {
        $client->request('GET', $this->hrefDoFacebook);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $q['state']);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->google = new GoogleLoginFalso();
        $this->google->email = 'nova_' . uniqid() . '@gmail.com';
        $this->google->sub = 'sub-novo-' . uniqid();
        $this->facebook = new FacebookLoginFalso();
        $this->facebook->id = 'fb-novo-' . uniqid();
        $this->facebook->email = null;
        static::getContainer()->set(GoogleLoginApi::class, $this->google->api());
        static::getContainer()->set(FacebookLoginApi::class, $this->facebook->api());
        // O botão do Facebook (com o token do CSRF) é lido com o cadastro aberto, antes de cada teste mudar a política.
        $this->hrefDoFacebook = (string) $client->request('GET', '/login?abrir=cadastro')->filter('#lgCdFacebook')->attr('href');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
