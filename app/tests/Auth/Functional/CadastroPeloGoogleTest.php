<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroPeloGoogleController;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\CadastroPeloGoogle;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Tests\Auth\GoogleLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Criar conta" conectado pelo Google (spec entrar-com-google, entrega 2; desenho 10 VS 24.10.26): a identidade vem
 * da sessão gravada na volta do Google, a conta nasce confirmada e ligada ao `sub`, sem link de confirmação, e entra
 * pelo véu. O botão do Facebook saiu até o app existir (decisão do dono de 24/10; ponto de ligação na spec).
 */
#[CoversClass(CadastroPeloGoogleController::class)]
#[CoversClass(CadastroPeloGoogle::class)]
final class CadastroPeloGoogleTest extends JusPrimeWebTestCase
{
    private GoogleLoginFalso $google;

    #[TestDox('Com o Google ligado: Continuar com Google e "ou com outro e-mail" acima do Nome, como no desenho; sem o Facebook')]
    public function testBotoesNoCadastro(): void
    {
        $client = $this->cliente();

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        $passo = '#lgMdForm > .lg-md__passo[data-passo="cad"]';
        self::assertCount(1, $crawler->filter($passo . ' > .lg-md__texto + #lgCdSoc.lg-soc + #lgCdSocOk.lg-socok[hidden] + label.lg-md__rotulo > #lgCdNome'));
        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdGoogle.lg-soc__btn:first-child + .lg-soc__ou'));
        self::assertSame('Continuar com Google', $crawler->filter('#lgCdGoogle')->text());
        self::assertStringStartsWith('/login/google?_token=', (string) $crawler->filter('#lgCdGoogle')->attr('href'));
        self::assertStringContainsString('origem=cadastro', (string) $crawler->filter('#lgCdGoogle')->attr('href'));
        self::assertSame('ou com outro e-mail', $crawler->filter('#lgCdSoc .lg-soc__ou')->text());
        self::assertCount(1, $crawler->filter('#lgCdEmailCampo:not([hidden]) > #lgCdEmail'));
        self::assertSame('Ex.: BlueJus Advogados Associados', $crawler->filter('#lgCdEsc')->attr('placeholder'));
        self::assertCount(0, $crawler->filter('#lgCdFacebook'));
        self::assertStringNotContainsStringIgnoringCase('facebook', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Sem o cliente OAuth do Google: o Continuar com Google sai do Criar conta (nenhum botão que não funciona), e nada do Facebook')]
    public function testSemGoogleSemBotao(): void
    {
        $client = $this->cliente(new GoogleLoginApi(new MockHttpClient()));

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        $passo = '#lgMdForm > .lg-md__passo[data-passo="cad"]';
        self::assertCount(0, $crawler->filter('#lgCdSoc, #lgCdGoogle, a[href^="/login/google"]'));
        self::assertCount(1, $crawler->filter($passo . ' > .lg-md__texto + #lgCdSocOk.lg-socok[hidden] + label.lg-md__rotulo > #lgCdNome'));
        self::assertCount(1, $crawler->filter('#lgCdEmailCampo:not([hidden]) > #lgCdEmail'));
        self::assertStringNotContainsString('Continuar com Google', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('#lgCdFacebook'));
        self::assertNull($crawler->filter('#lgRaiz')->attr('data-aviso-cadastro'), 'o aviso só vem depois do clique');
    }

    #[TestDox('Sem o cliente OAuth do Google: a ida do cadastro aberta direto volta ao "Criar conta" com o aviso, sem ir ao Google; o aviso vale uma vez')]
    public function testSemGoogleAvisoNoCadastro(): void
    {
        $chamadas = 0;
        $client = $this->cliente(new GoogleLoginApi(new MockHttpClient(static function () use (&$chamadas): MockResponse {
            ++$chamadas;

            return new MockResponse('{}');
        })));
        $client->request('GET', '/login?abrir=cadastro');

        $client->request('GET', '/login/google?origem=cadastro');

        self::assertResponseRedirects('/login?abrir=cadastro');
        self::assertSame(0, $chamadas, 'sem chamar o Google');
        $crawler = $client->followRedirect();
        self::assertSame('O acesso pelo Google ainda não está configurado neste ambiente. Use o e-mail.', $crawler->filter('#lgRaiz')->attr('data-aviso-cadastro'));
        self::assertTrue($crawler->filter('#lgErro')->matches('[hidden]'), 'no cadastro, o aviso não vai ao cartão de login');
        self::assertNull($client->request('GET', '/login?abrir=cadastro')->filter('#lgRaiz')->attr('data-aviso-cadastro'));
    }

    #[TestDox('Cria a conta pelo Google: e-mail do Google, confirmada, ligada ao sub, sem link de confirmação, aceite gravado e já entra pelo véu')]
    public function testCriaPeloGoogle(): void
    {
        $client = $this->cliente();
        $email = 'cadastro_' . uniqid() . '@gmail.com';
        $this->google->email = $email;
        $this->google->sub = 'sub-' . uniqid();
        $this->conectar($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', $this->campos($client) + ['email' => 'forjado@gmail.com']);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertStringStartsWith('/login/entrando?para=', $json['destino']);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'forjado@gmail.com']), 'o e-mail vem da sessão, não do JSON');
        self::assertTrue($user->isEmailConfirmado());
        self::assertSame($this->google->sub, $user->getGoogleSub());
        self::assertSame('Ana Google Souza', $user->getFullName());
        self::assertCount(0, $this->em()->getRepository(ConfirmacaoEmail::class)->findBy(['user' => $user]));
        self::assertCount(1, $this->em()->getRepository(UserTenant::class)->findBy(['user' => $user]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM aceite_termo WHERE user_id = :id', ['id' => $user->getId()]));
        $token = static::getContainer()->get('security.token_storage')->getToken();
        self::assertNotNull($token);
        self::assertSame($email, $token->getUserIdentifier());
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO), 'a identidade sai da sessão');
    }

    #[TestDox('Sem a identidade na sessão (expirou ou "Usar outro e-mail"): 422 com o aviso e a marca para a tela voltar aos botões')]
    public function testSemIdentidadeNaSessao(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $client->jsonRequest('POST', '/cadastro/google/esquecer', ['_token' => $this->token($client)]);
        self::assertResponseIsSuccessful();

        $client->jsonRequest('POST', '/cadastro/google/criar', $this->campos($client));

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(CadastroPeloGoogle::EXPIROU, $json['erro']);
        self::assertTrue($json['googleExpirou']);
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertCount(1, $crawler->filter('#lgCdSocOk[hidden]'));
    }

    #[TestDox('E-mail do Google que já tem conta: recusa com a mensagem própria e não cria outra')]
    public function testEmailQueJaTemConta(): void
    {
        $client = $this->cliente();
        $this->google->email = 'existe_' . uniqid() . '@gmail.com';
        $this->conectar($client);
        $outra = (new User())->setEmail(strtoupper($this->google->email))->setFullName('Outra Pessoa')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($outra);
        $this->em()->flush();

        $client->jsonRequest('POST', '/cadastro/google/criar', $this->campos($client));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(CriarContaEEscritorioUseCase::ERRO_JA_TEM_CONTA_GOOGLE, json_decode((string) $client->getResponse()->getContent(), true)['erro']);
    }

    #[TestDox('Validação do formulário (gênero): 422 com o texto do cadastro, e a identidade continua na sessão para corrigir')]
    public function testValidacaoMantemAIdentidade(): void
    {
        $client = $this->cliente();
        $this->conectar($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', ['genero' => ''] + $this->campos($client));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(CriarContaEEscritorioUseCase::ERRO_GENERO, json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertNotNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO));
    }

    #[TestDox('Quem já está logado não cria outra conta pelo Google: 403')]
    public function testLogadoNaoCria(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $campos = $this->campos($client);
        $logada = (new User())->setEmail('logada_' . uniqid() . '@adv.com')->setFullName('Logada Conta')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($logada);
        $this->em()->flush();
        $client->loginUser($logada);

        $client->jsonRequest('POST', '/cadastro/google/criar', $campos);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $this->google->email]));
    }

    #[TestDox('CSRF inválido: 403 no criar e no esquecer, e nada muda')]
    public function testCsrfInvalido(): void
    {
        $client = $this->cliente();
        $this->conectar($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', ['_token' => 'errado'] + $this->campos($client));
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', '/cadastro/google/esquecer', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO));
    }

    #[TestDox('Outra conta que entra com sucesso leva embora a identidade que esperava o cadastro (navegador compartilhado)')]
    public function testOutroLoginEsqueceAIdentidade(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $this->google->email = 'tem_conta_' . uniqid() . '@gmail.com';
        $this->google->sub = 'sub-outro-' . uniqid();
        $dona = (new User())->setEmail($this->google->email)->setFullName('Dona Conta')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($dona);
        $this->em()->flush();

        $this->voltarDoGoogle($client);

        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloGoogle::CHAVE_SESSAO));
    }

    /** Volta do Google com um e-mail sem conta: a identidade fica na sessão. */
    private function conectar(KernelBrowser $client): void
    {
        $this->voltarDoGoogle($client);
        self::assertResponseRedirects('/login?abrir=cadastro');
    }

    /** @return array<string, mixed> */
    private function campos(KernelBrowser $client): array
    {
        return ['nome' => 'Ana Google Souza', 'escritorio' => 'Souza Advogados', 'genero' => 'feminino', 'aceite' => true, '_token' => $this->token($client)];
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

    private function cliente(?GoogleLoginApi $api = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->google = new GoogleLoginFalso();
        $this->google->email = 'nova_' . uniqid() . '@gmail.com';
        $this->google->sub = 'sub-novo-' . uniqid();
        static::getContainer()->set(GoogleLoginApi::class, $api ?? $this->google->api());

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
