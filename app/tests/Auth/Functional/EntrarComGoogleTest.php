<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\EntrarComGoogleController;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Auth\Infra\GoogleLoginApi;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Security\GoogleAuthenticator;
use App\Tests\Auth\GoogleLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * "Entrar com o Google", entrega 1 (spec docs/specs/entrar-com-google.md): só quem já tem conta; ligação pelo `sub`
 * ou pelo e-mail confirmado; as barreiras e o destino do login por senha; "Manter conectado" só pelo próprio botão.
 */
#[CoversClass(EntrarComGoogleController::class)]
#[CoversClass(GoogleAuthenticator::class)]
final class EntrarComGoogleTest extends JusPrimeWebTestCase
{
    private GoogleLoginFalso $google;

    #[TestDox('A ida leva ao Google só com openid, email e profile, seletor de contas, state, nonce e PKCE S256; sem refresh token')]
    public function testIda(): void
    {
        $client = $this->cliente();

        $client->request('GET', '/login/google');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame('openid email profile', $q['scope']);
        self::assertSame('select_account', $q['prompt']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame(32, strlen((string) $q['state']));
        self::assertSame(32, strlen((string) $q['nonce']));
        self::assertArrayNotHasKey('access_type', $q, 'só identidade, nenhum token guardado');
        self::assertStringEndsWith('/login/google/callback', (string) $q['redirect_uri']);
    }

    #[TestDox('Sem o cliente OAuth no servidor: nenhum botão do Google na tela (nem o "ou"); a URL aberta direto volta ao login com o aviso exato, sem ir ao Google')]
    public function testDesligado(): void
    {
        $chamadas = 0;
        $client = $this->cliente(new GoogleLoginApi(new MockHttpClient(static function () use (&$chamadas): MockResponse {
            ++$chamadas;

            return new MockResponse('{}');
        })));
        $crawler = $client->request('GET', '/login');
        self::assertCount(0, $crawler->filter('a[href^="/login/google"]'), 'nenhum link para app_login_google');
        self::assertCount(0, $crawler->filter('#lgGoogle, #lgCdGoogle, #lgCdSoc, .lg-ou'));
        self::assertCount(1, $crawler->filter('form#lgForm > .lg-cartao__corpo > #lgEntrar + button.lg-selo'));
        self::assertStringNotContainsString('Entrar com o Google', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Continuar com Google', (string) $client->getResponse()->getContent());
        self::assertTrue($crawler->filter('#lgErro')->matches('[hidden]'));

        $client->request('GET', '/login/google');

        self::assertResponseRedirects('/login');
        self::assertSame(0, $chamadas, 'sem chamar o Google');
        $crawler = $client->followRedirect();
        self::assertSame('O acesso pelo Google ainda não está configurado neste ambiente. Use o e-mail.', $crawler->filter('#lgErro:not([hidden])')->text());
        self::assertCount(0, $crawler->filter('#lgEmail[aria-invalid], #lgSenha[aria-invalid]'), 'aviso, não erro de senha');
        self::assertNull($crawler->filter('#lgRaiz')->attr('data-aviso-cadastro'));
        $html = (string) $client->getResponse()->getContent();
        foreach (['GOOGLE_LOGIN', 'client_id', 'accounts.google.com', 'redirect_uri', 'OAuth'] as $vazamento) {
            self::assertStringNotContainsString($vazamento, $html, 'o aviso não revela nada além do texto');
        }
        self::assertTrue($client->request('GET', '/login')->filter('#lgErro')->matches('[hidden]'), 'o aviso vale uma vez');
    }

    #[TestDox('Sem o cliente OAuth no servidor, a URL aberta direto (sem token, origem qualquer) cai no aviso do cartão de login')]
    public function testDesligadoUrlDireta(): void
    {
        $client = $this->cliente(new GoogleLoginApi(new MockHttpClient()));

        $client->request('GET', '/login/google?origem=qualquer');

        self::assertResponseRedirects('/login');
        self::assertSame('O acesso pelo Google ainda não está configurado neste ambiente. Use o e-mail.', $client->followRedirect()->filter('#lgErro')->text());
    }

    #[TestDox('Com o cliente OAuth, a ida do cadastro (origem=cadastro) vai ao Google como antes, sem aviso')]
    public function testLigadoComOrigemCadastro(): void
    {
        $client = $this->cliente();

        $client->request('GET', '/login/google?origem=cadastro');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $client->getResponse()->headers->get('Location'));
        self::assertNull($client->request('GET', '/login?abrir=cadastro')->filter('#lgRaiz')->attr('data-aviso-cadastro'));
    }

    #[TestDox('Com o Google ligado, o botão fica logo abaixo do Entrar, com a linha "ou", e acima do selo; o contrato do formulário não muda')]
    public function testBotaoNaTela(): void
    {
        $client = $this->cliente();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('form#lgForm > .lg-cartao__corpo > #lgEntrar + .lg-ou + a#lgGoogle.lg-google + button.lg-selo'));
        self::assertStringContainsString('Entrar com o Google', $crawler->filter('#lgGoogle')->text());
        self::assertStringStartsWith('/login/google?_token=', (string) $crawler->filter('#lgGoogle')->attr('href'));
        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdGoogle[href^="/login/google?"][href*="origem=cadastro"]'), 'o Continuar com Google do Criar conta');
        self::assertCount(1, $crawler->filter('form#lgForm button#lgEntrar > i.bi-box-arrow-in-right'));
        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
        self::assertCount(1, $crawler->filter('a[href="/cadastro"]'));
    }

    #[TestDox('Conta que já existe entra pelo e-mail confirmado: fica ligada ao Google, o e-mail "a confirmar" é confirmado (evento uma vez) e passa pelo véu')]
    public function testEntraELiga(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $this->em()->getConnection()->executeStatement('UPDATE "user" SET email_a_confirmar_desde = NOW() WHERE id = :id', ['id' => $eu->getId()]);
        $confirmados = 0;
        static::getContainer()->get('event_dispatcher')->addListener(EmailDaContaConfirmado::class, static function () use (&$confirmados): void {
            ++$confirmados;
        });

        $this->voltarDoGoogle($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT google_sub, email_a_confirmar_desde FROM "user" WHERE id = :id', ['id' => $eu->getId()]);
        self::assertSame('sub-google-1', $linha['google_sub']);
        self::assertNull($linha['email_a_confirmar_desde']);
        self::assertSame(1, $confirmados);
        self::assertNotNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    #[TestDox('Ligada uma vez, a conta é achada pelo sub mesmo com outro e-mail no Google')]
    public function testPeloSub(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('antigo@escritorio.com.br', escritorios: 1);
        $this->em()->getConnection()->executeStatement("UPDATE \"user\" SET google_sub = 'sub-google-1' WHERE id = :id", ['id' => $eu->getId()]);
        $this->google->email = 'novo@gmail.com';

        $this->voltarDoGoogle($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Recusa: $_dataName. Volta ao login com a mensagem e nada é gravado')]
    #[DataProvider('recusas')]
    public function testRecusa(string $propriedade, mixed $valor, string $mensagem): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $this->google->{$propriedade} = $valor;

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects('/login');
        $crawler = $client->request('GET', '/login');
        self::assertSame($mensagem, trim($crawler->filter('#lgErro')->text()));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT google_sub FROM "user" WHERE id = :id', ['id' => $eu->getId()]));
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function recusas(): iterable
    {
        yield 'e-mail não confirmado pelo Google' => ['emailConfirmado', false, EntradaPeloGoogleRecusadaException::FALHOU];
        yield 'id_token de outro cliente' => ['audiencia', 'outro-cliente', EntradaPeloGoogleRecusadaException::FALHOU];
        yield 'id_token de outro emissor' => ['emissor', 'https://mal.example', EntradaPeloGoogleRecusadaException::FALHOU];
        yield 'id_token vencido' => ['validade', -60, EntradaPeloGoogleRecusadaException::FALHOU];
        yield 'nonce de outro pedido' => ['nonce', 'nonce-forjado', EntradaPeloGoogleRecusadaException::FALHOU];
        yield 'código recusado pelo Google' => ['recusarCodigo', true, EntradaPeloGoogleRecusadaException::FALHOU];
    }

    #[TestDox('State errado, ou volta sem ida: recusa. Cancelado no Google: volta com o aviso de cancelado')]
    public function testStateECancelado(): void
    {
        $client = $this->cliente();
        $this->usuario('pessoa@gmail.com', escritorios: 1);

        $client->request('GET', '/login/google/callback?code=abc&state=sem-ida');
        self::assertResponseRedirects('/login');
        self::assertSame(EntradaPeloGoogleRecusadaException::FALHOU, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $q = $this->ida($client);
        $client->request('GET', '/login/google/callback?code=abc&state=' . $q['state'] . 'x');
        self::assertSame(EntradaPeloGoogleRecusadaException::FALHOU, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $q = $this->ida($client);
        $client->request('GET', '/login/google/callback?error=access_denied&state=' . $q['state']);
        self::assertSame(EntradaPeloGoogleRecusadaException::CANCELADA, trim($client->request('GET', '/login')->filter('#lgErro')->text()));
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    #[TestDox('A URL de volta não serve duas vezes: o state sai da sessão no primeiro uso')]
    public function testReplayDaVolta(): void
    {
        $client = $this->cliente();
        $this->usuario('pessoa@gmail.com', escritorios: 1);
        $q = $this->ida($client);
        $this->google->nonce = $q['nonce'];
        $volta = '/login/google/callback?code=abc&state=' . $q['state'];

        $client->request('GET', $volta);
        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', $volta);

        self::assertResponseRedirects('/login');
    }

    #[TestDox('Sem conta (entrega 2): volta ao "Criar conta" conectado pelo Google, sem erro e sem login')]
    public function testSemContaAbreOCadastroConectado(): void
    {
        $client = $this->cliente();
        $this->google->email = 'nova@gmail.com';
        $this->google->nome = 'Nova Pessoa';

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects('/login?abrir=cadastro');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertCount(1, $crawler->filter('#lgErro[hidden]'));
        self::assertCount(1, $crawler->filter('#lgCdSoc[hidden]'));
        self::assertCount(1, $crawler->filter('#lgCdSocOk:not([hidden])[data-email="nova@gmail.com"]'));
        self::assertSame('Nova Pessoa', $crawler->filter('#lgCdSocNome')->text());
        self::assertSame('nova@gmail.com · conectado pelo Google', $crawler->filter('#lgCdSocOk .lg-socok__txt > span')->text());
        self::assertSame('Usar outro e-mail', $crawler->filter('#lgCdSocTrocar')->text());
        self::assertSame('Nova Pessoa', $crawler->filter('#lgCdNome')->attr('value'));
        self::assertCount(1, $crawler->filter('#lgCdEmailCampo[hidden]'));
    }

    #[TestDox('Decisão 10, sem conta: conta Google que não é Gmail nem Workspace não cria conta pelo Google')]
    public function testSemContaSemGarantiaNaoVaiAoCadastro(): void
    {
        $client = $this->cliente();
        $this->google->email = 'nova@empresa.com.br';

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects('/login');
        $crawler = $client->request('GET', '/login');
        self::assertSame(EntradaPeloGoogleRecusadaException::SEM_GARANTIA_CADASTRO, trim($crawler->filter('#lgErro')->text()));
        self::assertCount(1, $crawler->filter('#lgCdSocOk[hidden]'));
    }

    #[TestDox('Decisão 10, conta existente: e-mail sem a garantia do Google não liga pelo e-mail; com hd (Workspace), liga')]
    public function testCasamentoPorEmailSoComGarantia(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('ana@empresa.com.br', escritorios: 1);
        $this->google->email = 'ana@empresa.com.br';

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects('/login');
        self::assertSame(EntradaPeloGoogleRecusadaException::SEM_GARANTIA_ENTRAR, trim($client->request('GET', '/login')->filter('#lgErro')->text()));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT google_sub FROM "user" WHERE id = :id', ['id' => $eu->getId()]));

        $this->google->dominio = 'empresa.com.br';
        $this->voltarDoGoogle($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame('sub-google-1', $this->em()->getConnection()->fetchOne('SELECT google_sub FROM "user" WHERE id = :id', ['id' => $eu->getId()]));
    }

    #[TestDox('Decisão 9: conta "a confirmar" entra pelo Google, e a senha antiga (de quem cadastrou o e-mail) deixa de valer')]
    public function testContaAConfirmarPerdeASenhaAntiga(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $eu->setPassword($hasher->hashPassword($eu, 'senha-do-terceiro-1'));
        $eu->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->em()->flush();

        $this->voltarDoGoogle($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        $depois = $this->em()->find(User::class, $eu->getId());
        self::assertInstanceOf(User::class, $depois);
        self::assertTrue($depois->isEmailConfirmado());
        self::assertFalse($hasher->isPasswordValid($depois, 'senha-do-terceiro-1'));
    }

    #[TestDox('Conta a confirmar e inativa: o Google não liga, não confirma nem troca a senha; a entrada é recusada')]
    public function testContaAConfirmarInativaNaoELigada(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $eu->setPassword($hasher->hashPassword($eu, 'senha-antiga-1'));
        $eu->marcarEmailAConfirmar(new \DateTimeImmutable());
        $eu->setIsActive(false);
        $this->em()->flush();

        $this->voltarDoGoogle($client);

        self::assertResponseRedirects('/login');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        $this->em()->clear();
        $depois = $this->em()->find(User::class, $eu->getId());
        self::assertInstanceOf(User::class, $depois);
        self::assertFalse($depois->isEmailConfirmado());
        self::assertNull($depois->getGoogleSub());
        self::assertTrue($hasher->isPasswordValid($depois, 'senha-antiga-1'));
    }

    #[TestDox('Conta já confirmada entra pelo Google sem perder a própria senha')]
    public function testContaConfirmadaMantemASenha(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $eu->setPassword($hasher->hashPassword($eu, 'minha-senha-1'));
        $this->em()->flush();

        $this->voltarDoGoogle($client);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        $depois = $this->em()->find(User::class, $eu->getId());
        self::assertInstanceOf(User::class, $depois);
        self::assertTrue($hasher->isPasswordValid($depois, 'minha-senha-1'));
    }

    #[TestDox('Logado sem escritório escolhido e sem os Termos aceitos, as rotas do Google não caem nos portões (listas brancas)')]
    public function testRotasForaDosPortoes(): void
    {
        $client = $this->cliente();
        $client->loginUser($this->usuario('dois@gmail.com', escritorios: 2));

        $client->request('GET', '/login/google');
        self::assertStringStartsWith('https://accounts.google.com/', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', '/login/google/callback?code=abc&state=nenhum');
        self::assertResponseRedirects('/login');
    }

    #[TestDox('O e-mail já ligado a OUTRA conta Google é recusado; conta inativa não entra')]
    public function testOutroSubEInativo(): void
    {
        $client = $this->cliente();
        $eu = $this->usuario('pessoa@gmail.com', escritorios: 1);
        $this->em()->getConnection()->executeStatement("UPDATE \"user\" SET google_sub = 'sub-de-outro' WHERE id = :id", ['id' => $eu->getId()]);

        $this->voltarDoGoogle($client);
        self::assertSame(EntradaPeloGoogleRecusadaException::OUTRA_CONTA_GOOGLE, trim($client->request('GET', '/login')->filter('#lgErro')->text()));

        $this->em()->getConnection()->executeStatement('UPDATE "user" SET google_sub = NULL, is_active = false WHERE id = :id', ['id' => $eu->getId()]);
        $this->voltarDoGoogle($client);
        self::assertResponseRedirects('/login');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT google_sub FROM "user" WHERE id = :id', ['id' => $eu->getId()]), 'barrada, não liga');
    }

    #[TestDox('Destino igual ao da senha: Master sem escritório vai ao painel da plataforma; quem tem dois escritórios vai à seleção')]
    public function testDestino(): void
    {
        $client = $this->cliente();
        $this->usuario('master@gmail.com', escritorios: 0, papeis: ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->usuario('dois@gmail.com', escritorios: 2);

        $this->google->email = 'master@gmail.com';
        $this->google->sub = 'sub-master';
        $this->voltarDoGoogle($client);
        self::assertStringStartsWith('/login/entrando?para=/admin', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/logout?_csrf_token=csrf-token', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->google->email = 'dois@gmail.com';
        $this->google->sub = 'sub-dois';
        $this->voltarDoGoogle($client);
        self::assertStringStartsWith('/login/entrando?para=/escritorio/selecionar', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('"Manter conectado" vale com o token do próprio botão; manter=1 sem o token, ou _remember_me na volta, não liga o lembrar-me')]
    public function testManterConectado(): void
    {
        $client = $this->cliente();
        $this->usuario('pessoa@gmail.com', escritorios: 1);

        $this->voltarDoGoogle($client, '/login/google?manter=1');
        self::assertNull($client->getCookieJar()->get('REMEMBERME'), 'sem o token do botão');

        $q = $this->ida($client);
        $this->google->nonce = $q['nonce'];
        $client->request('GET', '/login/google/callback?code=abc&_remember_me=1&state=' . $q['state']);
        $this->google->nonce = null;
        self::assertNull($client->getCookieJar()->get('REMEMBERME'), '_remember_me na URL de volta não liga o lembrar-me');

        // Sair invalida a sessão (e o token da tela): o botão é lido de novo, como a pessoa faria.
        $client->request('GET', '/logout?_csrf_token=csrf-token', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $botao = (string) $client->request('GET', '/login')->filter('#lgGoogle')->attr('href');
        $this->voltarDoGoogle($client, $botao . '&manter=1');
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));
    }

    #[TestDox('Limitador da ida por IP: estourado, volta ao login com o aviso')]
    public function testLimitador(): void
    {
        $client = $this->cliente();
        static::getContainer()->set('limiter.login_google', new RateLimiterFactory(['id' => 'login_google_teste', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage()));

        $client->request('GET', '/login/google');
        self::assertStringStartsWith('https://accounts.google.com/', (string) $client->getResponse()->headers->get('Location'));
        $client->request('GET', '/login/google');

        self::assertResponseRedirects('/login');
        self::assertStringContainsString('Muitas tentativas', $client->request('GET', '/login')->filter('#lgErro')->text());
    }

    /** @return array<string, string> os parâmetros da URL de autorização */
    private function ida(KernelBrowser $client, string $caminho = '/login/google'): array
    {
        $client->request('GET', $caminho);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);

        return $q;
    }

    /** Ida e volta: o Google falso devolve o nonce da ida (a menos que o teste force outro). */
    private function voltarDoGoogle(KernelBrowser $client, string $caminho = '/login/google'): void
    {
        $q = $this->ida($client, $caminho);
        $forcado = $this->google->nonce;
        $this->google->nonce = $forcado ?? $q['nonce'];
        $client->request('GET', '/login/google/callback?code=abc&state=' . $q['state']);
        $this->google->nonce = $forcado;
    }

    private function cliente(?GoogleLoginApi $api = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->google = new GoogleLoginFalso();
        static::getContainer()->set(GoogleLoginApi::class, $api ?? $this->google->api());

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(string $email, int $escritorios, array $papeis = ['ROLE_USER']): User
    {
        $u = (new User())->setEmail($email)->setFullName('Pessoa ' . uniqid())->setRoles($papeis)->setIsActive(true);
        $this->em()->persist($u);
        for ($i = 0; $i < $escritorios; ++$i) {
            $tenant = (new Tenant())->setName('Tenant GL ' . uniqid());
            $this->em()->persist($tenant);
            $papel = (new TenantRole())->setTenant($tenant)->setName('Gestor ' . uniqid())->setIsSystem(true);
            $this->em()->persist($papel);
            $vinculo = new UserTenant($u, $tenant);
            $vinculo->setTenantRole($papel);
            $this->em()->persist($vinculo);
        }
        $this->em()->flush();

        return $u;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
