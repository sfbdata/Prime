<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\RecuperacaoSenhaController;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Entity\Auth\User;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fluxo público de recuperação de senha.
 *
 * Dois grupos de asserção que valem mais que os outros:
 * - **resposta neutra** — e-mail existente e inexistente têm que sair idênticos;
 * - **RS03** — trocar a senha derruba sessão viva e cookie "lembrar-me". Isso é
 *   comportamento do Symfony, não código nosso; o teste existe porque dependemos dele.
 */
#[CoversClass(RecuperacaoSenhaController::class)]
final class RecuperacaoSenhaControllerTest extends JusPrimeWebTestCase
{
    private const SENHA_ORIGINAL = 'senha-original-123';
    private const SENHA_NOVA     = 'senha-nova-456';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarUsuario(string $senha = self::SENHA_ORIGINAL, bool $ativo = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('recup_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Recuperação');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive($ativo);
        $user->setPassword($hasher->hashPassword($user, $senha));

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function criarPedido(User $user, string $token, string $expiraEm = '+1 hour'): RedefinicaoSenha
    {
        // Re-busca pelo id: depois de um login real o objeto do teste já está destacado
        // do EntityManager, e o Doctrine trataria a relação como entidade nova.
        $user = $this->em()->find(User::class, $user->getId());

        $pedido = new RedefinicaoSenha(
            user: $user,
            tokenHash: RedefinicaoSenha::hashDoToken($token),
            expiraEm: new \DateTimeImmutable($expiraEm),
            ip: '1.2.3.4',
        );
        $this->em()->persist($pedido);
        $this->em()->flush();

        return $pedido;
    }

    /** Token no formato que a rota aceita (64 hex), para não esbarrar no requirement. */
    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function pedirRedefinicao(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/senha/esqueci');
        $form    = $crawler->selectButton('Enviar link de redefinição')->form();
        $form['solicitar_redefinicao_senha[email]'] = $email;
        $client->submit($form);
    }

    #[TestDox('GET /senha/esqueci é acessível para anônimo e mostra o formulário')]
    public function testFormAcessivelAnonimo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/esqueci');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="solicitar_redefinicao_senha[email]"]');
    }

    #[TestDox('A tela de login aponta para a recuperação de senha (o link era morto)')]
    public function testLoginTemLinkParaRecuperacao(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
    }

    #[TestDox('Pedido válido cria o registro e envia o e-mail com um link que funciona')]
    public function testPedidoCriaRegistroEEnviaEmailComLinkUtil(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->enableProfiler();
        $this->pedirRedefinicao($client, $user->getEmail());

        self::assertResponseRedirects('/senha/enviado');
        self::assertEmailCount(1);

        $corpo = self::getMailerMessage()->getHtmlBody();
        self::assertIsString($corpo);
        self::assertSame(1, preg_match('#/senha/redefinir/([a-f0-9]{64})#', $corpo, $m), 'O e-mail precisa levar o link com o token.');

        // O token do e-mail tem que abrir o registro gravado — e o gravado é o HASH dele.
        $pedido = $this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user]);
        self::assertNotNull($pedido);
        self::assertSame(RedefinicaoSenha::hashDoToken($m[1]), $pedido->getTokenHash());
        self::assertStringNotContainsString($m[1], $pedido->getTokenHash());
    }

    #[TestDox('E-mail sem conta responde exatamente igual e não cria nem envia nada (RN02)')]
    public function testRespostaNeutraParaEmailInexistente(): void
    {
        $client = static::createClient();
        $antes  = $this->em()->getRepository(RedefinicaoSenha::class)->count([]);

        $client->enableProfiler();
        $this->pedirRedefinicao($client, 'ninguem_' . uniqid() . '@adv.com');

        self::assertResponseRedirects('/senha/enviado');
        self::assertEmailCount(0);
        self::assertSame($antes, $this->em()->getRepository(RedefinicaoSenha::class)->count([]));
    }

    #[TestDox('Conta desativada não recebe link, e a resposta continua a mesma (RS04)')]
    public function testUsuarioInativoNaoRecebeLink(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario(ativo: false);

        $client->enableProfiler();
        $this->pedirRedefinicao($client, $user->getEmail());

        self::assertResponseRedirects('/senha/enviado');
        self::assertEmailCount(0);
        self::assertNull($this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user]));
    }

    #[TestDox('Pedir de novo invalida o pedido anterior (RS02: um token vivo por vez)')]
    public function testPedidoNovoInvalidaOAnterior(): void
    {
        $client   = static::createClient();
        $user     = $this->criarUsuario();
        $antigo   = $this->token();
        $this->criarPedido($user, $antigo);

        $this->pedirRedefinicao($client, $user->getEmail());
        $this->em()->clear();

        self::assertNull(
            $this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['tokenHash' => RedefinicaoSenha::hashDoToken($antigo)]),
            'O token anterior tem que morrer quando um novo é pedido.',
        );
    }

    #[TestDox('O link do e-mail redefine a senha e o usuário entra com a senha nova')]
    public function testFluxoCompletoPermiteLogarComSenhaNova(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $this->criarPedido($user, $token);

        $crawler = $client->request('GET', '/senha/redefinir/' . $token);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Salvar nova senha')->form();
        $form['redefinir_senha[senha][first]']  = self::SENHA_NOVA;
        $form['redefinir_senha[senha][second]'] = self::SENHA_NOVA;
        $client->submit($form);

        self::assertResponseRedirects('/login');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->em()->clear();
        $atualizado = $this->em()->getRepository(User::class)->find($user->getId());

        self::assertTrue($hasher->isPasswordValid($atualizado, self::SENHA_NOVA));
        self::assertFalse($hasher->isPasswordValid($atualizado, self::SENHA_ORIGINAL), 'A senha antiga não pode continuar valendo.');
    }

    #[TestDox('Token já usado não abre o formulário (uso único)')]
    public function testTokenUsadoRecusado(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $pedido = $this->criarPedido($user, $token);
        $pedido->marcarUsado();
        $this->em()->flush();

        $client->request('GET', '/senha/redefinir/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
    }

    #[TestDox('Token expirado não abre o formulário')]
    public function testTokenExpiradoRecusado(): void
    {
        $client = static::createClient();
        $token  = $this->token();
        $this->criarPedido($this->criarUsuario(), $token, expiraEm: '-1 minute');

        $client->request('GET', '/senha/redefinir/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
    }

    #[TestDox('Token inexistente não abre o formulário')]
    public function testTokenInexistenteRecusado(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/redefinir/' . $this->token());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
    }

    #[TestDox('RS03 — redefinir a senha derruba a sessão que já estava aberta')]
    public function testRedefinicaoDerrubaSessaoViva(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('A sessão precisa estar valendo antes da troca.');

        // Outro dispositivo redefine a senha enquanto esta sessão está aberta.
        static::getContainer()->get(RedefinirSenhaUseCase::class)
            ->executar($this->tokenDePedidoNovo($user), self::SENHA_NOVA);

        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login');
    }

    #[TestDox('Conta gravada com maiúscula no e-mail consegue recuperar a senha (contra o SQL real)')]
    public function testContaComMaiusculaRecupera(): void
    {
        // O índice único de user.email é btree comum: para o banco, `Ana@` e `ana@` são
        // contas diferentes. Quem gravou com maiúscula loga normal e sumiria de uma busca
        // exata — ficando trancado fora justamente aqui. Este teste roda contra o LOWER()
        // de verdade, não contra mock.
        $client = static::createClient();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $sufixo = uniqid();
        $user   = new User();
        $user->setEmail('Ana_' . $sufixo . '@Adv.com');
        $user->setFullName('Dra. Ana Maiúscula');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA_ORIGINAL));
        $this->em()->persist($user);
        $this->em()->flush();

        $client->enableProfiler();
        $this->pedirRedefinicao($client, 'ana_' . $sufixo . '@adv.com');

        self::assertResponseRedirects('/senha/enviado');
        self::assertEmailCount(1, null, 'A conta existe; o link tem que sair.');
        self::assertNotNull($this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user]));
    }

    #[TestDox('Honeypot preenchido via HTTP não cria pedido nem envia e-mail, e responde igual')]
    public function testHoneypotPorHttp(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->enableProfiler();
        $crawler = $client->request('GET', '/senha/esqueci');
        $form    = $crawler->selectButton('Enviar link de redefinição')->form();
        $form['solicitar_redefinicao_senha[email]']       = $user->getEmail();
        $form['solicitar_redefinicao_senha[confirmacao]'] = 'sou-um-robo';
        $client->submit($form);

        self::assertResponseRedirects('/senha/enviado', null, 'A resposta tem que ser a mesma — o robô não pode saber que caiu na armadilha.');
        self::assertEmailCount(0);
        self::assertNull($this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user]));
    }

    #[TestDox('A tela /senha/enviado renderiza (é a última do fluxo)')]
    public function testTelaEnviadoRenderiza(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/enviado');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Verifique seu e-mail');
    }

    #[TestDox('Token fora do formato de 64 hex nem chega ao controller (404)')]
    public function testRequirementDaRota(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/redefinir/token-curto');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Uso único ponta a ponta: redefinir de verdade e tentar o MESMO link de novo')]
    public function testUsoUnicoPontaAPonta(): void
    {
        // Diferente de marcar `usadoEm` à mão: aqui o token é consumido pelo fluxo real,
        // que é onde o `exceto` do DELETE decide se a linha sobrevive marcada ou some.
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $this->criarPedido($user, $token);

        $crawler = $client->request('GET', '/senha/redefinir/' . $token);
        $form    = $crawler->selectButton('Salvar nova senha')->form();
        $form['redefinir_senha[senha][first]']  = self::SENHA_NOVA;
        $form['redefinir_senha[senha][second]'] = self::SENHA_NOVA;
        $client->submit($form);
        self::assertResponseRedirects('/login');

        // Segunda tentativa com o mesmo link.
        $client->request('GET', '/senha/redefinir/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');

        // E a senha não voltou a mudar por causa da segunda visita.
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->em()->clear();
        self::assertTrue($hasher->isPasswordValid($this->em()->getRepository(User::class)->find($user->getId()), self::SENHA_NOVA));
    }

    #[TestDox('POST inválido (sem CSRF, sem e-mail) NÃO consome cota de rate limit')]
    public function testPostInvalidoNaoConsomeCota(): void
    {
        // Este é o defeito que a revisão pegou: com o limiter consumido antes do CSRF,
        // cinco POSTs anônimos de custo zero derrubavam a recuperação de senha da
        // plataforma inteira por uma hora — atrás do nginx o IP é o mesmo para todos.
        $client = static::createClient();
        $client->disableReboot();
        $espioes = $this->espionarLimiters();

        // Submete o formulário DE VERDADE (com o nome certo dos campos) mas com CSRF
        // inválido. Um POST com corpo aleatório nem chega a ser "submetido" pelo Symfony,
        // e o teste não percorreria o caminho que diz percorrer.
        $crawler = $client->request('GET', '/senha/esqueci');
        $form    = $crawler->selectButton('Enviar link de redefinição')->form();
        $form['solicitar_redefinicao_senha[email]']  = 'alvo_' . uniqid() . '@adv.com';
        $form['solicitar_redefinicao_senha[_token]'] = 'csrf-invalido';
        $client->submit($form);

        // 422 é o que o Symfony devolve ao renderizar um form submetido e inválido.
        // (Com corpo aleatório vinha 200, porque o form nem chegava a ser submetido —
        // era esse o furo do teste anterior.)
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form input[name="solicitar_redefinicao_senha[email]"]');
        self::assertSame([], $espioes['ip']->chavesConsumidas, 'POST inválido não pode gastar a cota do IP.');
        self::assertSame([], $espioes['email']->chavesConsumidas);
    }

    #[TestDox('Cota estourada devolve 429 e não cria pedido nenhum')]
    public function testCotaEstouradaDevolve429(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.senha_esqueci_ip', new RateLimiterFactoryEspiao(aceita: false));

        $user  = $this->criarUsuario();
        $antes = $this->em()->getRepository(RedefinicaoSenha::class)->count([]);

        $this->pedirRedefinicao($client, $user->getEmail());

        self::assertResponseStatusCodeSame(429);
        self::assertSame($antes, $this->em()->getRepository(RedefinicaoSenha::class)->count([]), 'Barrado no limite: nada é gravado.');
    }

    #[TestDox('POST válido consome as duas cotas, e a do e-mail é normalizada')]
    public function testPostValidoConsomeAsDuasCotas(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $espioes = $this->espionarLimiters();

        $crawler = $client->request('GET', '/senha/esqueci');
        $form    = $crawler->selectButton('Enviar link de redefinição')->form();
        $form['solicitar_redefinicao_senha[email]'] = '  ALVO@Adv.com ';
        $client->submit($form);

        self::assertResponseRedirects('/senha/enviado');
        self::assertCount(1, $espioes['ip']->chavesConsumidas);
        self::assertSame(
            ['alvo@adv.com'],
            $espioes['email']->chavesConsumidas,
            'A cota por e-mail precisa usar a chave normalizada, senão trocar a caixa zera o limite.',
        );
    }

    /**
     * Troca os dois rate limiters por espiões que delegam ao real.
     *
     * @return array{ip: RateLimiterFactoryEspiao, email: RateLimiterFactoryEspiao}
     */
    private function espionarLimiters(): array
    {
        $container = static::getContainer();

        // Substituir sem ler antes: obter o serviço real já o inicializa, e o container
        // de teste recusa trocar serviço inicializado.
        $ip    = new RateLimiterFactoryEspiao();
        $email = new RateLimiterFactoryEspiao();

        $container->set('limiter.senha_esqueci_ip', $ip);
        $container->set('limiter.senha_esqueci_email', $email);

        return ['ip' => $ip, 'email' => $email];
    }

    #[TestDox('Guarda do teste de remember-me: sem o cookie, esquecerSessao() derruba mesmo a autenticação')]
    public function testEsquecerSessaoDerrubaAutenticacaoSemCookie(): void
    {
        // Sem esta prova, o teste seguinte poderia passar por engano: se esquecerSessao()
        // não apagasse a sessão de verdade, o 200 do meio viria dela — e não do cookie.
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful();

        $this->esquecerSessao($client);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login', null, 'Sem sessão e sem remember-me não pode haver autenticação.');
    }

    #[TestDox('RS03 — o cookie "lembrar-me" antigo para de autenticar depois da troca')]
    public function testRedefinicaoDerrubaRememberMe(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        // Login real pelo formulário: só assim o cookie remember-me é emitido.
        $crawler = $client->request('GET', '/login');
        $form    = $crawler->selectButton('Entrar')->form();
        $form['email']    = $user->getEmail();
        $form['password'] = self::SENHA_ORIGINAL;
        $form['_remember_me']->tick();
        $client->submit($form);

        $cookieRemember = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookieRemember, 'O login com "manter conectado" precisa emitir o cookie.');

        // Derruba só a sessão: o que autenticar daqui em diante vem do cookie.
        $this->esquecerSessao($client);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('O cookie sozinho tem que autenticar antes da troca.');

        static::getContainer()->get(RedefinirSenhaUseCase::class)
            ->executar($this->tokenDePedidoNovo($user), self::SENHA_NOVA);

        $this->esquecerSessao($client);
        $client->getCookieJar()->set($cookieRemember);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login', null, 'O cookie antigo não pode mais autenticar: a assinatura inclui o hash da senha.');
    }

    /** Cria um pedido válido e devolve o token em claro, para acionar o UseCase direto. */
    private function tokenDePedidoNovo(User $user): string
    {
        $token = $this->token();
        $this->criarPedido($user, $token);

        return $token;
    }

    /** Expira o cookie de sessão sem tocar no remember-me. */
    private function esquecerSessao(KernelBrowser $client): void
    {
        foreach ($client->getCookieJar()->all() as $cookie) {
            if ($cookie->getName() !== 'REMEMBERME') {
                $client->getCookieJar()->expire($cookie->getName());
            }
        }
    }
}
