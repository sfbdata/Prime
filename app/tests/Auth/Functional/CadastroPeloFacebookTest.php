<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroPeloFacebookController;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Tests\Auth\FacebookLoginFalso;
use App\Tests\Auth\GoogleLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * "Criar conta" conectado pelo Facebook (spec login-cupom-e-facebook, PR B). O Facebook não prova e-mail: a conta nasce
 * NÃO confirmada, pelo mesmo cadastro por e-mail (link de confirmação), com o e-mail do corpo do POST; o `id` do
 * Facebook vem da sessão, nunca do navegador, e só é gravado na conta nova.
 */
#[CoversClass(CadastroPeloFacebookController::class)]
#[CoversClass(CadastroPeloFacebook::class)]
final class CadastroPeloFacebookTest extends JusPrimeWebTestCase
{
    private FacebookLoginFalso $facebook;

    #[TestDox('Com o Facebook ligado: o botão vem logo depois do Google, dentro do #lgCdSoc, antes do "ou com outro e-mail", com o texto do desenho')]
    public function testBotaoDepoisDoGoogle(): void
    {
        $client = $this->cliente();

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdGoogle.lg-soc__btn:first-child + a#lgCdFacebook.lg-soc__btn + .lg-soc__ou'));
        self::assertSame('Continuar com Facebook', $crawler->filter('#lgCdFacebook')->text());
        self::assertStringStartsWith('/login/facebook?_token=', (string) $crawler->filter('#lgCdFacebook')->attr('href'));
        self::assertCount(1, $crawler->filter('#lgCdFacebook > svg'));
    }

    #[TestDox('Sem credencial do Facebook o botão some e o do Google segue')]
    public function testBotaoSomeSemCredencial(): void
    {
        $client = $this->cliente(new FacebookLoginApi(new MockHttpClient()));
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertCount(0, $crawler->filter('#lgCdFacebook, a[href^="/login/facebook"]'));
        self::assertStringNotContainsString('Continuar com Facebook', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdGoogle'));

    }

    #[TestDox('Só com o Facebook ligado (sem o Google): o bloco e o "ou" aparecem sem o botão do Google')]
    public function testSoFacebook(): void
    {
        $client = $this->cliente(null, new GoogleLoginApi(new MockHttpClient()));

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        self::assertCount(0, $crawler->filter('#lgCdGoogle'));
        self::assertCount(1, $crawler->filter('#lgCdSoc > a#lgCdFacebook + .lg-soc__ou'));
    }

    #[TestDox('Cartão conectado: mostra o provedor e o nome; o campo de e-mail continua VISÍVEL e vem preenchido com a sugestão do Facebook')]
    public function testCartaoConectadoComEmailVisivel(): void
    {
        $client = $this->cliente();
        $this->facebook->email = 'sugestao@exemplo.com';
        $this->facebook->nome = 'Ana Facebook Souza';
        $this->conectar($client);

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        $passo = '#lgMdForm > .lg-md__passo[data-passo="cad"]';
        self::assertCount(1, $crawler->filter('#lgCdSoc[hidden]'));
        self::assertCount(1, $crawler->filter($passo . ' > #lgCdSocOk.lg-socok[data-provedor="facebook"]:not([hidden])'));
        self::assertSame('Ana Facebook Souza', $crawler->filter('#lgCdSocNome')->text());
        self::assertSame('Conectado pelo Facebook', $crawler->filter('#lgCdSocOk .lg-socok__txt > span')->text());
        self::assertSame('', (string) $crawler->filter('#lgCdSocOk')->attr('data-email'), 'o e-mail do Facebook não vira o e-mail da conta');
        self::assertCount(1, $crawler->filter('#lgCdEmailCampo:not([hidden]) > #lgCdEmail[value="sugestao@exemplo.com"]'));
        self::assertSame('Ana Facebook Souza', $crawler->filter('#lgCdNome')->attr('value'));
    }

    #[TestDox('Cria a conta: e-mail do corpo, NÃO confirmada, com facebook_id, link de confirmação, aceite gravado, sem login; a identidade sai da sessão')]
    public function testCriaNaoConfirmada(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-' . uniqid();
        $this->facebook->email = 'sugestao@exemplo.com';
        $this->conectar($client);
        $email = 'Digitado_' . uniqid() . '@Exemplo.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertTrue($json['confirmar']);
        self::assertSame(strtolower($email), $json['email']);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'sugestao@exemplo.com']), 'a sugestão do Facebook não cria conta');
        self::assertFalse($user->isEmailConfirmado());
        self::assertSame($this->facebook->id, $user->getFacebookId());
        self::assertNull($user->getGoogleSub());
        self::assertCount(1, $this->em()->getRepository(ConfirmacaoEmail::class)->findBy(['user' => $user]));
        self::assertCount(1, $this->em()->getRepository(UserTenant::class)->findBy(['user' => $user]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM aceite_termo WHERE user_id = :id', ['id' => $user->getId()]));
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken(), 'não faz login');
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO), 'a identidade sai da sessão');
        self::assertSame($user->getId(), $client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO)['id'], 'o reenvio vale para a conta nova');
        self::assertEmailCount(1);
        self::assertSame(strtolower($email), self::getMailerMessage()?->getTo()[0]->getAddress());
    }

    #[TestDox('O link do e-mail confirma a conta nascida pelo Facebook e cria a senha; o facebook_id segue ligado')]
    public function testLinkConfirma(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-' . uniqid();
        $this->conectar($client);
        $email = 'link_' . uniqid() . '@exemplo.com';
        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/([a-f0-9]{64})#', (string) self::getMailerMessage()?->getHtmlBody(), $achado));

        $form = $client->request('GET', '/cadastro/confirmar-email/' . $achado[1])->selectButton('Criar senha e confirmar')->form();
        $form['senha'] = 'SenhaBoa123';
        $form['confirmacao'] = 'SenhaBoa123';
        $form['aceite']->tick();
        $client->submit($form);

        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEmailConfirmado());
        self::assertSame($this->facebook->id, $user->getFacebookId());
    }

    #[TestDox('Pré-sequestro (a): link aberto na MESMA sessão que criou a conta mantém o facebook_id e o login pelo Facebook funciona')]
    public function testLinkNaMesmaSessaoMantemFacebook(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-' . uniqid();
        $this->conectar($client);
        $email = 'mesma_' . uniqid() . '@exemplo.com';
        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);
        $token = $this->tokenDoEmail();

        $this->criarSenhaPeloLink($client, $token);

        self::assertSame($this->facebook->id, $this->facebookIdDe($email));
        $this->loginPeloFacebook($this->outroNavegador($client), $this->facebook->id, true);
    }

    #[TestDox('Pré-sequestro (b): link aberto em OUTRA sessão apaga o facebook_id e o login pelo Facebook passa a falhar')]
    public function testLinkEmOutraSessaoApagaFacebook(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-' . uniqid();
        $this->conectar($client);
        $email = 'outra_' . uniqid() . '@exemplo.com';
        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);
        $token = $this->tokenDoEmail();

        $this->criarSenhaPeloLink($this->outroNavegador($client), $token);

        self::assertNull($this->facebookIdDe($email));
        $this->loginPeloFacebook($this->outroNavegador($client), $this->facebook->id, false);
    }

    #[TestDox('Pré-sequestro (c): atacante cria a conta com o e-mail da vítima e o id dele; a vítima confirma em outro navegador; o atacante não entra pelo Facebook')]
    public function testCenarioDoAtacante(): void
    {
        $atacante = $this->cliente();
        $this->facebook->id = 'fb-atacante';
        $this->conectar($atacante);
        $emailDaVitima = 'vitima_' . uniqid() . '@exemplo.com';
        $atacante->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($atacante) + ['email' => $emailDaVitima]);
        self::assertResponseIsSuccessful();
        $token = $this->tokenDoEmail();

        // A vítima abre o link que chegou na caixa dela, no navegador dela.
        $this->criarSenhaPeloLink($this->outroNavegador($atacante), $token);

        self::assertTrue($this->em()->getRepository(User::class)->findOneBy(['email' => $emailDaVitima])?->isEmailConfirmado());
        self::assertNull($this->facebookIdDe($emailDaVitima));
        $this->loginPeloFacebook($this->outroNavegador($atacante), 'fb-atacante', false);
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    #[TestDox('Facebook sem e-mail: o campo vem vazio e a pessoa informa o dela; sem e-mail válido no corpo, 422 e a identidade fica para corrigir')]
    public function testSemEmailDoFacebook(): void
    {
        $client = $this->cliente();
        $this->facebook->email = null;
        $this->conectar($client);
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        self::assertSame('', (string) $crawler->filter('#lgCdEmail')->attr('value'));

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(CriarContaEEscritorioUseCase::ERRO_EMAIL, json_decode((string) $client->getResponse()->getContent(), true)['erro']);
        self::assertNotNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[TestDox('E-mail que já tem conta: a mesma resposta de um cadastro novo, nada é ligado (facebook_id da dona intacto) e nenhuma conta nova nasce')]
    public function testEmailDeContaExistenteNaoLiga(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-do-atacante';
        $this->conectar($client);
        $dona = (new User())->setEmail('dona_' . uniqid() . '@exemplo.com')->setFullName('Dona Conta')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $dona->ligarAoFacebook('fb-da-dona');
        $this->em()->persist($dona);
        $this->em()->flush();
        $antes = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM "user"');

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => strtoupper((string) $dona->getEmail())]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($json['ok']);
        self::assertTrue($json['confirmar']);
        self::assertSame($antes, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM "user"'));
        self::assertSame('fb-da-dona', $this->em()->getConnection()->fetchOne('SELECT facebook_id FROM "user" WHERE id = :id', ['id' => $dona->getId()]));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
        self::assertTrue($client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO)['neutro']);
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    #[TestDox('facebook_id já ligado a outra conta: a mesma resposta neutra, sem criar conta nenhuma')]
    public function testFacebookIdJaUsado(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-repetido-' . uniqid();
        $outra = (new User())->setEmail('outra_' . uniqid() . '@exemplo.com')->setFullName('Outra Conta')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $outra->ligarAoFacebook($this->facebook->id);
        $this->em()->persist($outra);
        $this->em()->flush();
        // A volta cai direto no login da conta ligada; a identidade pendente é forjada na sessão para testar a barreira do cadastro.
        $this->conectarSemConta($client, $this->facebook->id);
        $novo = 'novo_' . uniqid() . '@exemplo.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $novo]);

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['confirmar']);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $novo]));
        self::assertEmailCount(0);
    }

    #[TestDox('A identidade é lida só da sessão: facebook_id e provedor no JSON são ignorados')]
    public function testIdentidadeSoDaSessao(): void
    {
        $client = $this->cliente();
        $this->facebook->id = 'fb-da-sessao';
        $this->conectar($client);
        $email = 'sessao_' . uniqid() . '@exemplo.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email, 'facebook_id' => 'fb-forjado', 'facebookId' => 'fb-forjado', 'id' => 'fb-forjado']);

        self::assertResponseIsSuccessful();
        self::assertSame('fb-da-sessao', $this->em()->getRepository(User::class)->findOneBy(['email' => $email])?->getFacebookId());
    }

    #[TestDox('Sem a identidade na sessão (expirou ou "Usar outro e-mail"): 422 com a marca facebookExpirou, e nada é criado')]
    public function testSemIdentidade(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $client->jsonRequest('POST', '/cadastro/facebook/esquecer', ['_token' => $this->token($client)]);
        self::assertResponseIsSuccessful();
        $email = 'expirou_' . uniqid() . '@exemplo.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);

        self::assertResponseStatusCodeSame(422);
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(CadastroPeloFacebook::EXPIROU, $json['erro']);
        self::assertTrue($json['facebookExpirou']);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $email]));
        self::assertCount(1, $client->request('GET', '/login?abrir=cadastro')->filter('#lgCdSocOk[hidden]'));
    }

    #[TestDox('Identidade vencida (mais de 15 minutos): 422 facebookExpirou')]
    public function testIdentidadeVencida(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $sessao = $client->getRequest()->getSession();
        $guardada = $sessao->get(CadastroPeloFacebook::CHAVE_SESSAO);
        $guardada['em'] = time() - 3600;
        $sessao->set(CadastroPeloFacebook::CHAVE_SESSAO, $guardada);
        $sessao->save();

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => 'venceu_' . uniqid() . '@exemplo.com']);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['facebookExpirou']);
    }

    #[TestDox('Quem já está logado não cria outra conta pelo Facebook: 403')]
    public function testLogadoNaoCria(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $campos = $this->campos($client);
        $logada = (new User())->setEmail('logada_' . uniqid() . '@adv.com')->setFullName('Logada Conta')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($logada);
        $this->em()->flush();
        $client->loginUser($logada);

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $campos + ['email' => 'nao_' . uniqid() . '@exemplo.com']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('CSRF inválido: 403 no criar e no esquecer, e a identidade continua na sessão')]
    public function testCsrfInvalido(): void
    {
        $client = $this->cliente();
        $this->conectar($client);

        $client->jsonRequest('POST', '/cadastro/facebook/criar', ['_token' => 'errado', 'email' => 'x@exemplo.com'] + $this->campos($client));
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', '/cadastro/facebook/esquecer', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        self::assertNotNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[TestDox('Outra conta que entra com sucesso leva embora a identidade do Facebook que esperava o cadastro')]
    public function testOutroLoginEsqueceAIdentidade(): void
    {
        $client = $this->cliente();
        $this->conectar($client);
        $dona = (new User())->setEmail('ligada_' . uniqid() . '@exemplo.com')->setFullName('Dona Ligada')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $dona->ligarAoFacebook('fb-ligado');
        $this->em()->persist($dona);
        $this->em()->flush();
        $this->facebook->id = 'fb-ligado';

        $client->request('GET', (string) $client->request('GET', '/login?abrir=cadastro')->filter('#lgCdFacebook')->attr('href'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $q['state']);

        self::assertStringStartsWith('/login/entrando?para=', (string) $client->getResponse()->headers->get('Location'));
        self::assertNull($client->getRequest()->getSession()->get(CadastroPeloFacebook::CHAVE_SESSAO));
    }

    #[TestDox('O cupom de indicação (PR A) vale também pelo Facebook: o código do campo liga a conta nova a quem indicou')]
    public function testCupomPeloFacebook(): void
    {
        $client = $this->cliente();
        $indicador = (new User())->setEmail('indicador_' . uniqid() . '@adv.com')->setFullName('Ana Indica')->setRoles(['ROLE_USER'])->setPassword('x');
        $this->em()->persist($indicador);
        $this->em()->flush();
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?)', [$indicador->getId(), 'FACE2345']);
        $this->facebook->id = 'fb-' . uniqid();
        $this->conectar($client);
        $email = 'cupom_' . uniqid() . '@exemplo.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email, 'indicacao' => 'BJ-FACE2345']);

        self::assertResponseIsSuccessful();
        $nova = (int) $conexao->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        self::assertSame($indicador->getId(), (int) $conexao->fetchOne('SELECT indicado_por_id FROM indicacao WHERE indicado_user_id = ?', [$nova]));
    }

    private function tokenDoEmail(): string
    {
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/([a-f0-9]{64})#', (string) self::getMailerMessage()?->getHtmlBody(), $achado));

        return $achado[1];
    }

    /** Outro navegador: o mesmo kernel, sem os cookies (outra sessão). */
    private function outroNavegador(KernelBrowser $client): KernelBrowser
    {
        $client->getCookieJar()->clear();

        return $client;
    }

    private function criarSenhaPeloLink(KernelBrowser $client, string $token): void
    {
        $form = $client->request('GET', '/cadastro/confirmar-email/' . $token)->selectButton('Criar senha e confirmar')->form();
        $form['senha'] = 'SenhaBoa123';
        $form['confirmacao'] = 'SenhaBoa123';
        $form['aceite']->tick();
        $client->submit($form);
    }

    private function facebookIdDe(string $email): ?string
    {
        $v = $this->em()->getConnection()->fetchOne('SELECT facebook_id FROM "user" WHERE email = ?', [$email]);

        return false === $v ? null : $v;
    }

    /** Ida e volta do Facebook com o id dado: loga (conta ligada e confirmada) ou não. */
    private function loginPeloFacebook(KernelBrowser $client, string $id, bool $deveLogar): void
    {
        $this->facebook->id = $id;
        $href = (string) $client->request('GET', '/login?abrir=cadastro')->filter('#lgCdFacebook')->attr('href');
        $client->request('GET', $href);
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $q['state']);
        $destino = (string) $client->getResponse()->headers->get('Location');
        if ($deveLogar) {
            self::assertStringStartsWith('/login/entrando?para=', $destino);
        } else {
            self::assertSame('/login?abrir=cadastro', $destino, 'sem conta ligada ao id: vai ao cadastro, não loga');
        }
    }

    /** Volta do Facebook com um id sem conta: a identidade fica na sessão. */
    private function conectar(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/login?abrir=cadastro');
        $client->request('GET', (string) $crawler->filter('#lgCdFacebook')->attr('href'));
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $client->request('GET', '/login/facebook/callback?code=abc&state=' . $q['state']);
        self::assertResponseRedirects('/login?abrir=cadastro');
    }

    /** Grava a identidade direto na sessão (o caso de um id que passou a ter conta depois da volta). */
    private function conectarSemConta(KernelBrowser $client, string $id): void
    {
        $client->request('GET', '/login');
        $sessao = $client->getRequest()->getSession();
        $sessao->set(CadastroPeloFacebook::CHAVE_SESSAO, ['id' => $id, 'nome' => 'Fulano de Tal', 'email' => null, 'em' => time()]);
        $sessao->save();
    }

    /** @return array<string, mixed> */
    private function campos(KernelBrowser $client): array
    {
        return ['nome' => 'Ana Facebook Souza', 'escritorio' => 'Souza Advogados', 'genero' => 'feminino', 'aceite' => true, '_token' => $this->token($client)];
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/login')->filter('#lgRaiz')->attr('data-token-cadastro');
    }

    private function cliente(?FacebookLoginApi $api = null, ?GoogleLoginApi $google = null): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->facebook = new FacebookLoginFalso();
        $this->facebook->id = 'fb-novo-' . uniqid();
        $this->facebook->email = null;
        static::getContainer()->set(FacebookLoginApi::class, $api ?? $this->facebook->api());
        static::getContainer()->set(GoogleLoginApi::class, $google ?? (new GoogleLoginFalso())->api());

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
