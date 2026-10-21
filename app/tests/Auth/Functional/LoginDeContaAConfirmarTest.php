<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\UseCase\AceitarConviteEscritorioSemContaUseCase;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use App\Controller\SecurityController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Security\PedidoDeNavegacao;
use App\Security\SessaoDeContaAConfirmarListener;
use App\Security\UserChecker;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Conta com o e-mail "a confirmar" não usa o sistema (spec `cadastro-exige-confirmacao.md`): o login (formulário e
 * lembrar-me) recusa depois da senha, com a mensagem e o botão de reenviar; a sessão já aberta termina no próximo
 * pedido; confirmar pelo link ou trocar a senha pelo código destrava; quem nasce por convite continua entrando.
 */
#[CoversClass(UserChecker::class)]
#[CoversClass(SessaoDeContaAConfirmarListener::class)]
#[CoversClass(SecurityController::class)]
final class LoginDeContaAConfirmarTest extends JusPrimeWebTestCase
{
    private const SENHA = 'segredo123';
    private const ERRO_DE_CREDENCIAL = 'E-mail ou senha incorretos. Verifique seus dados e tente novamente.';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conta(bool $aConfirmar = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail('aconfirmar_' . uniqid() . '@adv.com')->setFullName('Ana Souza')
            ->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA));
        if ($aConfirmar) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }
        $tenant = (new Tenant())->setName('Escritório ' . uniqid());
        $this->em()->persist($user);
        $this->em()->persist($tenant);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function enviarLogin(KernelBrowser $client, string $email, string $senha, bool $lembrar = false): void
    {
        $form = $client->request('GET', '/login')->selectButton('Entrar')->form();
        $form['email'] = $email;
        $form['password'] = $senha;
        if ($lembrar) {
            $form['_remember_me']->tick();
        }
        $client->submit($form);
    }

    private function entrou(KernelBrowser $client): bool
    {
        return $client->getContainer()->get('security.token_storage')->getToken() !== null;
    }

    private static function assertEntrouPeloVeu(KernelBrowser $client): void
    {
        self::assertResponseRedirects();
        self::assertSame('/login/entrando', parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    /** A tela de entrada com a recusa e o botão de reenviar. */
    private static function assertTelaPedeConfirmacao(Crawler $tela, User $user): void
    {
        self::assertSame(ContaAConfirmar::mensagemDeLogin((string) $user->getEmail()), trim($tela->filter('#lgErro')->text()));
        self::assertNull($tela->filter('#lgErro')->attr('hidden'));
        self::assertSame(ContaAConfirmar::BOTAO_REENVIAR, trim($tela->filter('#lgReenviar[type="submit"][form="lgReenvio"]')->text()));
        self::assertCount(1, $tela->filter('form#lgReenvio[method="post"][action="/login/reenviar-confirmacao"] > input[name="_token"]'));
    }

    /** O id guardado na chave do reenvio (a chave guarda também o momento). */
    private function chaveNaSessao(KernelBrowser $client): mixed
    {
        $valor = $client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO);

        return is_array($valor) ? ($valor['id'] ?? null) : $valor;
    }

    /** A resposta manda o cookie do lembrar-me vencido (o Symfony apaga com expiração no passado). */
    private static function assertApagouOLembrarMe(KernelBrowser $client): void
    {
        $lembrar = array_values(array_filter(
            $client->getResponse()->headers->getCookies(),
            static fn (Cookie $c): bool => $c->getName() === 'REMEMBERME',
        ));
        self::assertCount(1, $lembrar, 'a resposta tem de apagar o cookie do lembrar-me');
        self::assertTrue($lembrar[0]->isCleared(), 'o REMEMBERME volta com expiração no passado');
    }

    #[TestDox('Login pelo formulário de conta a confirmar, com a senha certa: recusado com a mensagem e o botão de reenviar')]
    public function testLoginDeContaAConfirmarERecusado(): void
    {
        $client = static::createClient();
        $user = $this->conta();

        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);

        self::assertResponseRedirects('/login');
        self::assertFalse($this->entrou($client));
        self::assertSame($user->getId(), $this->chaveNaSessao($client));
        $tela = $client->followRedirect();
        self::assertTelaPedeConfirmacao($tela, $user);

        // O botão reenvia o link para a própria conta e volta ao login.
        $client->submit($tela->filter('form#lgReenvio')->form());
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        self::assertSame((string) $user->getEmail(), self::getMailerMessage()?->getTo()[0]->getAddress());
    }

    #[TestDox('Senha errada da mesma conta: o erro de credencial comum, sem revelar a conta nem mostrar o botão')]
    public function testSenhaErradaNaoRevelaAConta(): void
    {
        $client = static::createClient();
        $user = $this->conta();

        $this->enviarLogin($client, (string) $user->getEmail(), 'senha-errada-1');

        self::assertResponseRedirects('/login');
        self::assertNull($this->chaveNaSessao($client));
        $tela = $client->followRedirect();
        self::assertSame(self::ERRO_DE_CREDENCIAL, trim($tela->filter('#lgErro')->text()));
        self::assertCount(0, $tela->filter('#lgReenviar, form#lgReenvio'));
        self::assertStringNotContainsString('Confirme o seu e-mail', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Recusa antiga na sessão e senha errada de outra conta: só o erro de credencial, sem o botão')]
    public function testOutroErroNaoLevaOBotao(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);

        $this->enviarLogin($client, 'outra_' . uniqid() . '@adv.com', 'senha-errada-1');
        $tela = $client->followRedirect();

        self::assertSame(self::ERRO_DE_CREDENCIAL, trim($tela->filter('#lgErro')->text()));
        self::assertCount(0, $tela->filter('#lgReenviar, form#lgReenvio'));
    }

    #[TestDox('Lembrar-me de conta que voltou a ficar a confirmar não entra e cai no login com a mensagem')]
    public function testLembrarMeNaoEntra(): void
    {
        $client = static::createClient();
        $user = $this->conta(aConfirmar: false);
        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA, lembrar: true);
        self::assertEntrouPeloVeu($client);
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));

        $this->em()->find(User::class, $user->getId())?->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->em()->flush();
        foreach ($client->getCookieJar()->all() as $cookie) {
            if ($cookie->getName() !== 'REMEMBERME') {
                $client->getCookieJar()->expire($cookie->getName());
            }
        }
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login');
        self::assertApagouOLembrarMe($client);
        self::assertFalse($this->entrou($client));
        self::assertSame($user->getId(), $this->chaveNaSessao($client));
        self::assertTelaPedeConfirmacao($client->followRedirect(), $user);
    }

    #[TestDox('Sessão já aberta de conta a confirmar: o próximo pedido de página encerra a sessão e leva ao login com a mensagem')]
    public function testSessaoAbertaTerminaNaNavegacao(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/expediente');

        self::assertResponseRedirects('/login');
        self::assertApagouOLembrarMe($client);
        self::assertFalse($this->entrou($client));
        self::assertTelaPedeConfirmacao($client->followRedirect(), $user);

        $client->request('GET', '/expediente');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'), 'a sessão acabou de vez');
    }

    #[TestDox('Sessão já aberta de conta a confirmar: pedido de dados recebe 401 em JSON e a sessão acaba')]
    public function testSessaoAbertaPedidoDeDados401(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/notificacoes/count', [], [], ['HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_ACCEPT' => '*/*']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['erro' => PedidoDeNavegacao::MENSAGEM_SESSAO_VENCIDA], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame($user->getId(), $this->chaveNaSessao($client));
        $client->request('GET', '/expediente');
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Sessão aberta de conta confirmada segue normal')]
    public function testSessaoDeContaConfirmadaSegue(): void
    {
        $client = static::createClient();
        $user = $this->conta(aConfirmar: false);
        $tenant = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user])?->getTenant();
        self::assertNotNull($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', '/notificacoes/count', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->entrou($client));
    }

    /** Abre o link do e-mail e cria a senha pelo formulário "Crie sua senha" (spec cadastro-senha-no-link). */
    private function criarSenhaPeloLink(KernelBrowser $client, string $token, string $senha): void
    {
        $form = $client->request('GET', '/cadastro/confirmar-email/' . $token)->selectButton('Criar senha e confirmar')->form();
        $form['senha'] = $senha;
        $form['confirmacao'] = $senha;
        $form['aceite']->tick();
        $client->submit($form);
        self::assertSelectorTextContains('.confirmacao-email', 'Sua senha foi criada.');
    }

    private function linkDeConfirmacao(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $this->em()->persist(new ConfirmacaoEmail($user, ConfirmacaoEmail::hashDoToken($token), new \DateTimeImmutable('+6 days')));
        $this->em()->flush();

        return $token;
    }

    #[TestDox('Conta antiga a confirmar (com senha): o link pede a senha nova; a antiga deixa de valer e a nova entra')]
    public function testCriarSenhaPeloLinkEEntrar(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $token = $this->linkDeConfirmacao($user);

        $client->request('GET', '/cadastro/confirmar-email/' . $token);
        self::assertSelectorTextSame('.confirmacao-email h3', 'Crie sua senha');
        $this->criarSenhaPeloLink($client, $token, 'novaSenha456');
        self::assertFalse($this->entrou($client), 'o link confirma, mas não faz login sozinho');

        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);
        self::assertResponseRedirects('/login');
        self::assertSame(self::ERRO_DE_CREDENCIAL, trim($client->followRedirect()->filter('#lgErro')->text()));

        $this->enviarLogin($client, (string) $user->getEmail(), 'novaSenha456');
        self::assertEntrouPeloVeu($client);
    }

    #[TestDox('O cookie lembrar-me antigo cai depois que a senha é criada pelo link (a assinatura leva a senha)')]
    public function testRememberMeAntigoCaiDepoisDeCriarASenha(): void
    {
        $client = static::createClient();
        $user = $this->conta(aConfirmar: false);
        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA, lembrar: true);
        self::assertEntrouPeloVeu($client);
        $lembrarMe = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($lembrarMe);

        // A conta volta a ficar a confirmar (como as de produção do #201) e o link cria a senha nova.
        $gerenciado = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($gerenciado);
        $gerenciado->marcarEmailAConfirmar(new \DateTimeImmutable());
        $token = $this->linkDeConfirmacao($gerenciado);
        $client->getCookieJar()->clear();
        $this->criarSenhaPeloLink($client, $token, 'novaSenha456');
        $this->em()->clear();
        self::assertTrue($this->em()->find(User::class, $user->getId())?->isEmailConfirmado());

        // Só o cookie antigo, sem sessão: a conta está confirmada, mas o hash mudou e a assinatura não bate mais.
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($lembrarMe);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login');
        self::assertFalse($this->entrou($client));
    }

    #[TestDox('Recuperar a senha pelo código (que confirma o e-mail) e depois entrar funciona')]
    public function testRecuperarSenhaPeloCodigoEEntrar(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $segredo = (string) static::getContainer()->getParameter('kernel.secret');
        $this->em()->persist(new RedefinicaoSenha(
            user: $user,
            tokenHash: SolicitarCodigoSenhaUseCase::hashDoCodigo((int) $user->getId(), '123456', $segredo),
            expiraEm: new \DateTimeImmutable('+15 minutes'),
            ip: '127.0.0.1',
        ));
        $this->em()->flush();
        $token = (string) $client->request('GET', '/login')->filter('#lgRaiz')->attr('data-token-senha');

        $client->jsonRequest('POST', '/senha/codigo/confirmar', [
            'email' => $user->getEmail(), 'codigo' => '123456', 'senha' => 'novaSenha456', 'confirmacao' => 'novaSenha456', '_token' => $token,
        ]);
        self::assertResponseIsSuccessful();

        $this->enviarLogin($client, (string) $user->getEmail(), 'novaSenha456');
        self::assertEntrouPeloVeu($client);
    }

    #[TestDox('Conta criada pelo convite de escritório nasce confirmada e entra')]
    public function testConviteDeEscritorioEntra(): void
    {
        $client = static::createClient();
        $tenant = (new Tenant())->setName('Escritório que convida ' . uniqid());
        $email = 'convidada_' . uniqid() . '@adv.com';
        $token = bin2hex(random_bytes(16));
        $convite = new Invitation(email: $email, token: $token, type: 'office', expiresAt: new \DateTimeImmutable('+7 days'));
        $convite->setTenant($tenant);
        $this->em()->persist($tenant);
        $this->em()->persist($convite);
        $this->em()->flush();

        $user = static::getContainer()->get(AceitarConviteEscritorioSemContaUseCase::class)->executar(
            new AceitarConviteEscritorioSemContaInput(token: $token, fullName: 'Bia Convidada', senha: self::SENHA, aceiteTermos: true, ip: '127.0.0.1'),
        );
        self::assertTrue($user->isEmailConfirmado());

        $this->enviarLogin($client, $email, self::SENHA);
        self::assertEntrouPeloVeu($client);
    }

    #[TestDox('Cadastro de ponta a ponta: nasce sem login, a senha do cadastro não entra, o reenvio respeita o teto e a senha criada no link entra')]
    public function testCadastroDePontaAPonta(): void
    {
        $client = static::createClient();
        $sessao = $client->getSession();
        $sessao->set('_csrf/cadastro_criar', 'token-do-cadastro');
        $sessao->save();
        $email = 'ponta_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/criar', [
            'nome' => 'Ana Souza', 'escritorio' => 'Souza ' . uniqid(), 'email' => $email,
            'senha' => self::SENHA, 'aceite' => true, '_token' => 'token-do-cadastro',
        ]);
        self::assertSame(['ok' => true, 'confirmar' => true, 'email' => $email], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertFalse($this->entrou($client));
        self::assertSame(1, preg_match('#/cadastro/confirmar-email/([a-f0-9]{64})#', (string) self::getMailerMessage()?->getHtmlBody(), $link));
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        // Recarregar a tela de entrada mostra o pedido de confirmação; o reenvio logo depois bate no teto de 2 min.
        $tela = $client->request('GET', '/login');
        self::assertTelaPedeConfirmacao($tela, $user);
        $client->jsonRequest('POST', '/login/reenviar-confirmacao', ['_token' => (string) $tela->filter('#lgRaiz')->attr('data-token-reenvio')], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(['ok' => false, 'mensagem' => 'Aguarde um instante antes de pedir outro link de confirmação.'], json_decode((string) $client->getResponse()->getContent(), true));

        // A senha digitada no cadastro nunca vale (spec cadastro-senha-no-link): o erro é o de credencial comum.
        $this->enviarLogin($client, $email, self::SENHA);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->entrou($client));
        self::assertSame(self::ERRO_DE_CREDENCIAL, trim($client->followRedirect()->filter('#lgErro')->text()));

        // O link pede a senha; a do cadastro continua sem valer depois da confirmação, e a criada entra.
        $client->request('GET', '/cadastro/confirmar-email/' . $link[1]);
        self::assertSelectorTextSame('.confirmacao-email h3', 'Crie sua senha');
        $this->criarSenhaPeloLink($client, $link[1], 'novaSenha456');
        // D2: o aceite do link entra ao lado do do cadastro, sem apagar.
        $origens = fn (string $tabela): array => $this->em()->getConnection()->fetchFirstColumn(
            'SELECT origem FROM ' . $tabela . ' WHERE user_id = :id ORDER BY id',
            ['id' => $user->getId()],
        );
        self::assertSame(['cadastro', 'link_confirmacao'], $origens('aceite_termo'));
        self::assertSame(['cadastro', 'link_confirmacao'], $origens('ciencia_politica'));
        $this->enviarLogin($client, $email, self::SENHA);
        self::assertResponseRedirects('/login');
        self::assertFalse($this->entrou($client));
        self::assertSame(self::ERRO_DE_CREDENCIAL, trim($client->followRedirect()->filter('#lgErro')->text()));
        $this->enviarLogin($client, $email, 'novaSenha456');
        self::assertEntrouPeloVeu($client);
    }

    #[TestDox('Navegador compartilhado: outra conta entra com sucesso e a chave sai; o /login não mostra mais o aviso nem o botão')]
    public function testOutraContaEntraEAChaveSai(): void
    {
        $client = static::createClient();
        $user = $this->conta();
        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);
        self::assertSame($user->getId(), $this->chaveNaSessao($client));

        // O Master logado continua vendo a tela de entrada: é por ela que se confere o que sobrou da outra pessoa.
        $master = $this->conta(aConfirmar: false)->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em()->flush();
        $this->enviarLogin($client, (string) $master->getEmail(), self::SENHA);
        self::assertEntrouPeloVeu($client);
        self::assertNull($this->chaveNaSessao($client));

        $tela = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('#lgReenviar, form#lgReenvio'));
        self::assertStringNotContainsString((string) $user->getEmail(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('A chave vale 30 minutos: depois, o /login não mostra o aviso e o reenvio público responde o texto neutro')]
    public function testChaveVenceEmTrintaMinutos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $relogio = new MockClock(new \DateTimeImmutable());
        static::getContainer()->set(ClockInterface::class, $relogio);
        $user = $this->conta();
        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);
        self::assertTelaPedeConfirmacao($client->followRedirect(), $user);

        $relogio->sleep(31 * 60);
        $tela = $client->request('GET', '/login');

        self::assertCount(0, $tela->filter('#lgReenviar, form#lgReenvio'));
        self::assertSame('', trim($tela->filter('#lgErro')->text()));
        $client->jsonRequest('POST', '/login/reenviar-confirmacao', ['_token' => (string) $tela->filter('#lgRaiz')->attr('data-token-reenvio')], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(['ok' => true, 'mensagem' => ContaAConfirmar::SEM_CONTA_NA_SESSAO], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame('Para receber um novo acesso, use "Esqueceu a senha?": enviamos um código para o seu e-mail.', ContaAConfirmar::SEM_CONTA_NA_SESSAO);
        self::assertEmailCount(0);
    }

    #[TestDox('O modal do cadastro tem o estado "Confirme seu e-mail" com os textos da spec, o reenvio e o "Ir para o login"')]
    public function testModalDoCadastroTemOEstadoDeConfirmacao(): void
    {
        $client = static::createClient();
        $tela = $client->request('GET', '/login');

        $passo = $tela->filter('#lgMdForm > .lg-md__passo[data-passo="conf"]');
        self::assertCount(1, $passo);
        self::assertSame('Confirme seu e-mail', $passo->attr('data-titulo'));
        self::assertSame('Reenviar o link de confirmação', $passo->attr('data-botao'));
        $textos = $passo->filter('.lg-md__texto');
        self::assertSame('Enviamos um link de confirmação para . Clique nele para criar a sua senha e ativar sua conta e seu escritório. O link expira em 7 dias.', trim($textos->eq(0)->text()));
        self::assertCount(1, $textos->eq(0)->filter('b#lgCfEmail'));
        self::assertSame('Não recebeu? Verifique a caixa de spam ou peça um novo link.', trim($textos->eq(1)->text()));
        self::assertSame('Ir para o login', trim($tela->filter('#lgMdForm > .lg-md__jatem[data-passo="conf"] > a[data-md-fechar]')->text()));
        self::assertNotSame('', (string) $tela->filter('#lgRaiz')->attr('data-token-reenvio'));
        // Sem conta a confirmar na sessão, a tela de entrada não mostra o botão de reenvio nem a mensagem.
        self::assertCount(0, $tela->filter('#lgReenviar, form#lgReenvio'));
        self::assertSame('', trim($tela->filter('#lgErro')->text()));
    }
}
