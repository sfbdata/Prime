<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroController;
use App\Auth\Controller\ContaConfirmacaoController;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\Service\TetoDoReenvioDeConfirmacao;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\TermoVigente;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cadastro do desenho 10 (spec `login-desenho-10.md`, comportamento 4) e o `/cadastro/confirmar/{token}` antigo, que
 * continua para os cadastros pendentes já enviados.
 */
#[CoversClass(CadastroController::class)]
final class CadastroControllerTest extends JusPrimeWebTestCase
{
    private const CSRF = 'token-de-teste-do-cadastro';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Grava o token da intenção `cadastro_criar` direto na sessão do cliente, como o `csrf_token()` da tela faria.
     * Sem requisição antes: o espião do limitador posto no container sobrevive até o POST.
     */
    private function prepararCsrf(KernelBrowser $client): void
    {
        $session = $client->getSession();
        $session->set('_csrf/cadastro_criar', self::CSRF);
        $session->save();
    }

    /** @param array<string, mixed> $trocas */
    private static function dados(array $trocas = []): array
    {
        return array_merge([
            'nome' => 'Ana Souza',
            'escritorio' => 'Souza Advogados ' . uniqid(),
            'email' => 'ana_' . uniqid() . '@adv.com',
            'aceite' => true,
            '_token' => self::CSRF,
        ], $trocas);
    }

    /** @return array<string, mixed> */
    private static function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function usuario(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setFullName('Já Existe')->setRoles(['ROLE_USER'])->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function criarPendente(string $email): CadastroPendente
    {
        $cadastro = new CadastroPendente(
            $email,
            'tok_' . uniqid(),
            'Dra. Nova',
            'Banca Nova ' . uniqid(),
            '12345',
            'SP',
            'HASH_QUALQUER',
            '1.2.3.4',
            new \DateTimeImmutable('+1 hour'),
            'UA',
        );
        $this->em()->persist($cadastro);
        $this->em()->flush();

        return $cadastro;
    }

    private function logado(KernelBrowser $client): User
    {
        $tenant = (new Tenant())->setName('Escritório Logado ' . uniqid());
        $this->em()->persist($tenant);
        $logado = $this->usuario('logado_' . uniqid() . '@adv.com');
        $this->em()->persist(new UserTenant($logado, $tenant));
        $this->em()->flush();
        $this->logarComTenant($client, $logado, $tenant);

        return $logado;
    }

    #[TestDox('GET /cadastro leva à tela de entrada com o cadastro aberto')]
    public function testGetRedirecionaParaATelaDeEntrada(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cadastro');

        self::assertResponseRedirects('/login?abrir=cadastro');
    }

    #[TestDox('GET /cadastro de quem já está logado vai para a seleção de escritório')]
    public function testGetLogadoVaiParaSelecao(): void
    {
        $client = static::createClient();
        $this->logado($client);
        $client->request('GET', '/cadastro');

        self::assertResponseRedirects('/escritorio/selecionar');
    }

    #[TestDox('POST /cadastro/criar cria conta e escritório, grava aceite e ciência, manda o e-mail e NÃO entra: pede a confirmação')]
    public function testCriaContaSemLogin(): void
    {
        $client = static::createClient();
        $this->prepararCsrf($client);
        $email = 'Nova_' . uniqid() . '@ADV.com';
        $dados = self::dados(['email' => '  ' . $email . ' ', 'nome' => ' Ana  Souza ']);

        $client->jsonRequest('POST', '/cadastro/criar', $dados, ['HTTP_USER_AGENT' => 'Navegador de teste']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertSame(strtolower($email), self::getMailerMessage()?->getTo()[0]->getAddress());
        self::assertSame(['ok' => true, 'confirmar' => true, 'email' => strtolower($email)], self::json($client));

        $em = $this->em();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertNull($user->getOabNumero());
        self::assertFalse($user->isEmailConfirmado());
        self::assertNotNull($user->getEmailAConfirmarDesde());

        $tenant = $em->getRepository(Tenant::class)->findOneBy(['name' => $dados['escritorio']]);
        self::assertNotNull($tenant);
        self::assertSame($user->getId(), $tenant->getCriadoPor()?->getId());
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        self::assertTrue($vinculo->isActive());

        $aceite = $em->getRepository(AceiteTermo::class)->findOneBy(['user' => $user]);
        self::assertNotNull($aceite);
        self::assertSame(TermoVigente::VERSAO, $aceite->getVersao());
        $ciencia = $em->getRepository(CienciaPolitica::class)->findOneBy(['user' => $user]);
        self::assertNotNull($ciencia);
        self::assertSame(PoliticaPrivacidadeVigente::VERSAO, $ciencia->getVersao());
        self::assertSame('Navegador de teste', $ciencia->getUserAgent());
        self::assertCount(1, $em->getRepository(ConfirmacaoEmail::class)->findBy(['user' => $user, 'lembrete' => false]));

        // Não entrou (spec cadastro-exige-confirmacao): sem token, sem escritório na sessão; a sessão guarda a conta
        // só para o botão de reenviar o link.
        self::assertNull($client->getContainer()->get('security.token_storage')->getToken());
        self::assertNull($client->getRequest()->getSession()->get('current_tenant_id'));
        self::assertSame($user->getId(), $client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO)['id'] ?? null);
        $client->request('GET', '/cadastro');
        self::assertResponseRedirects('/login?abrir=cadastro');
    }

    #[TestDox('POST /cadastro/criar de cliente antigo que ainda manda a senha: a senha é ignorada (D1 da spec cadastro-senha-no-link)')]
    public function testSenhaDeClienteAntigoEIgnorada(): void
    {
        $client = static::createClient();
        $this->prepararCsrf($client);
        $dados = self::dados(['senha' => 'segredo123']);

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $dados['email']]);
        self::assertNotNull($user);
        self::assertFalse(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'segredo123'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidos(): iterable
    {
        yield 'nome de uma palavra' => [['nome' => 'Ana'], 'Informe seu nome completo.'];
        yield 'nome longo demais' => [['nome' => 'Ana ' . str_repeat('a', 252)], 'Informe seu nome completo.'];
        yield 'escritório vazio' => [['escritorio' => '   '], 'Informe o nome do escritório.'];
        yield 'escritório longo demais' => [['escritorio' => str_repeat('e', 256)], 'Informe o nome do escritório.'];
        yield 'e-mail inválido' => [['email' => 'ana@adv'], 'Informe um e-mail válido.'];
        yield 'e-mail longo demais' => [['email' => str_repeat('a', 173) . '@adv.com'], 'Informe um e-mail válido.'];
        yield 'e-mail com cópia na query' => [['email' => 'a@b.com?cc=c@d.com'], 'Informe um e-mail válido.'];
        yield 'dois e-mails com vírgula' => [['email' => 'a@b.com,c@d.com'], 'Informe um e-mail válido.'];
        yield 'sem aceite' => [['aceite' => false], 'É preciso aceitar os Termos e a Política de privacidade.'];
        yield 'campo ausente' => [['nome' => null], 'Informe seu nome completo.'];
    }

    /** @param array<string, mixed> $trocas */
    #[DataProvider('invalidos')]
    #[TestDox('Validação devolve 422 com o texto do desenho: $mensagem')]
    public function testValidacao(array $trocas, string $mensagem): void
    {
        $client = static::createClient();
        $this->prepararCsrf($client);
        $dados = self::dados($trocas);

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $mensagem], self::json($client));
        self::assertEmailCount(0);
        self::assertNull($this->em()->getRepository(Tenant::class)->findOneBy(['name' => $dados['escritorio']]));
    }

    #[TestDox('Raiz E: e-mail já cadastrado (com outra caixa) recebe a mesma resposta do e-mail novo, sem escritório nem sessão; a dona recebe o aviso')]
    public function testEmailDuplicadoComOutraCaixa(): void
    {
        $client = static::createClient();
        $email = 'dup_' . uniqid() . '@adv.com';
        $existente = $this->usuario($email);
        $this->prepararCsrf($client);
        $dados = self::dados(['email' => '  ' . strtoupper($email) . ' ']);

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'confirmar' => true, 'email' => $email], self::json($client));
        self::assertNull($this->em()->getRepository(Tenant::class)->findOneBy(['name' => $dados['escritorio']]));
        // Na sessão, só o marcador neutro: nenhuma conta (nem a da dona do e-mail).
        $chave = $client->getRequest()->getSession()->get(ContaAConfirmar::CHAVE_SESSAO);
        self::assertTrue($chave['neutro'] ?? false);
        self::assertArrayNotHasKey('id', $chave);
        self::assertCount(0, $this->em()->getRepository(ConfirmacaoEmail::class)->findBy(['user' => $existente]));

        self::assertEmailCount(1);
        $aviso = self::getMailerMessage();
        self::assertSame($email, $aviso?->getTo()[0]->getAddress());
        self::assertStringContainsString('Você já tem uma conta no BlueJus', (string) $aviso?->getSubject());
        self::assertStringContainsString('/senha/esqueci', (string) $aviso?->getHtmlBody());
        self::assertStringNotContainsString('/cadastro/confirmar-email/', (string) $aviso?->getHtmlBody());
    }

    #[TestDox('Raiz E: e-mail novo e e-mail existente dão o mesmo status e o mesmo JSON; o aviso de conta existente só sai para o existente')]
    public function testEmailNovoEExistenteRespondemIgual(): void
    {
        $existente = 'igual_' . uniqid() . '@adv.com';
        $this->usuario($existente);
        $novo = 'igual_novo_' . uniqid() . '@adv.com';

        $respostas = [];
        $assuntos = [];
        foreach ([$existente, $novo] as $email) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->prepararCsrf($client);
            $client->jsonRequest('POST', '/cadastro/criar', self::dados(['email' => $email]));
            $json = self::json($client);
            $respostas[] = [$client->getResponse()->getStatusCode(), array_keys($json), $json['ok'], $json['confirmar']];
            self::assertSame($email, $json['email']);
            self::assertEmailCount(1);
            $assuntos[$email] = self::getMailerMessage()?->getSubject();
        }

        self::assertSame($respostas[0], $respostas[1]);
        self::assertSame([200, ['ok', 'confirmar', 'email'], true, true], $respostas[0]);
        self::assertStringContainsString('Você já tem uma conta no BlueJus', (string) $assuntos[$existente]);
        self::assertStringNotContainsString('Você já tem uma conta', (string) $assuntos[$novo]);
    }

    /**
     * Cadastro e três cliques no "Reenviar" do modal (já, 3 min depois, e logo em seguida), num cliente sem reboot e
     * com o relógio de mentira. Devolve, por clique, o status, o JSON e quantos e-mails saíram.
     *
     * @return list<array{int, array<string, mixed>, int}>
     */
    private function cadastroEReenvios(string $email): array
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        $relogio = new MockClock(new \DateTimeImmutable());
        static::getContainer()->set(ClockInterface::class, $relogio);
        $this->prepararCsrf($client);
        $client->jsonRequest('POST', '/cadastro/criar', self::dados(['email' => $email]));
        self::assertResponseIsSuccessful();

        $sessao = $client->getSession();
        $sessao->set('_csrf/' . ContaAConfirmar::CSRF, self::CSRF);
        $sessao->save();

        $cliques = [];
        foreach ([0, 3 * 60, 0] as $espera) {
            $relogio->sleep($espera);
            $client->jsonRequest('POST', '/login/reenviar-confirmacao', ['_token' => self::CSRF]);
            $cliques[] = [$client->getResponse()->getStatusCode(), self::json($client), count(self::getMailerMessages())];
        }

        return $cliques;
    }

    #[TestDox('Raiz E: o "Reenviar" do modal responde igual para o e-mail novo e para o que já tem conta (mesmo teto), e nada sai para a conta existente')]
    public function testReenvioDepoisDoEmailExistenteRespondeIgual(): void
    {
        $existente = 'reenvio_' . uniqid() . '@adv.com';
        $this->usuario($existente);

        $doExistente = $this->cadastroEReenvios($existente);
        $doNovo = $this->cadastroEReenvios('reenvio_novo_' . uniqid() . '@adv.com');

        $semContagem = static fn (array $cliques): array => array_map(static fn (array $c): array => [$c[0], $c[1]], $cliques);
        self::assertSame($semContagem($doNovo), $semContagem($doExistente));
        self::assertSame([
            [200, ['ok' => false, 'mensagem' => TetoDoReenvioDeConfirmacao::AGUARDE]],
            [200, ['ok' => true, 'mensagem' => ContaConfirmacaoController::FLASH_ENVIADO]],
            [200, ['ok' => false, 'mensagem' => TetoDoReenvioDeConfirmacao::AGUARDE]],
        ], $semContagem($doNovo));
        self::assertSame([0, 0, 0], array_column($doExistente, 2));
        self::assertSame([0, 1, 0], array_column($doNovo, 2));
    }

    #[TestDox('Raiz E: a tela de entrada mostra o mesmo aviso de conta a confirmar depois do cadastro de um e-mail existente')]
    public function testTelaDeEntradaIgualDepoisDoEmailExistente(): void
    {
        $email = 'tela_' . uniqid() . '@adv.com';
        $client = static::createClient();
        $this->usuario($email);
        $this->prepararCsrf($client);
        $client->jsonRequest('POST', '/cadastro/criar', self::dados(['email' => $email]));

        $client->request('GET', '/login');

        self::assertStringContainsString(ContaAConfirmar::mensagemDeLogin($email), (string) $client->getResponse()->getContent());
    }

    #[TestDox('Raiz E: conta existente inativa (ou em quarentena) não recebe nada, com a mesma resposta')]
    public function testContaExistenteInativaNaoRecebeNada(): void
    {
        $email = 'inativa_' . uniqid() . '@adv.com';
        $client = static::createClient();
        $this->usuario($email)->setIsActive(false);
        $this->em()->flush();
        $this->prepararCsrf($client);

        $client->jsonRequest('POST', '/cadastro/criar', self::dados(['email' => $email]));

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'confirmar' => true, 'email' => $email], self::json($client));
        self::assertEmailCount(0);
    }

    #[TestDox('Raiz E: conta existente ainda a confirmar recebe o link de confirmação normal, não o "já tem conta"')]
    public function testContaExistenteAConfirmarRecebeAConfirmacao(): void
    {
        $email = 'aconfirmar_' . uniqid() . '@adv.com';
        $client = static::createClient();
        $user = $this->usuario($email);
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();
        $this->prepararCsrf($client);

        $client->jsonRequest('POST', '/cadastro/criar', self::dados(['email' => $email]));

        self::assertSame(['ok' => true, 'confirmar' => true, 'email' => $email], self::json($client));
        self::assertEmailCount(1);
        self::assertStringContainsString('/cadastro/confirmar-email/', (string) self::getMailerMessage()?->getHtmlBody());
        self::assertStringNotContainsString('já tem uma conta', (string) self::getMailerMessage()?->getSubject());
        self::assertCount(1, $this->em()->getRepository(ConfirmacaoEmail::class)->findBy(['user' => $user]));
    }

    #[TestDox('CSRF inválido devolve 403 e não gasta a cota do IP')]
    public function testCsrfInvalido(): void
    {
        $client = static::createClient();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.cadastro_auto', $espiao);
        $this->prepararCsrf($client);
        $dados = self::dados(['_token' => 'outro']);

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], self::json($client));
        self::assertSame([], $espiao->chavesConsumidas);
        self::assertSame([], $this->em()->getRepository(User::class)->findBy(['email' => $dados['email']]));
    }

    #[TestDox('Cota do IP estourada devolve 429')]
    public function testLimite(): void
    {
        $client = static::createClient();
        static::getContainer()->set('limiter.cadastro_auto', new RateLimiterFactoryEspiao(aceita: false));
        $this->prepararCsrf($client);
        $dados = self::dados();

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], self::json($client));
        self::assertSame([], $this->em()->getRepository(User::class)->findBy(['email' => $dados['email']]));
    }

    #[TestDox('Quem está logado não cria outra conta: 403')]
    public function testLogadoNaoCria(): void
    {
        $client = static::createClient();
        $this->logado($client);
        $dados = self::dados();

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Saia da sua conta para criar outra.'], self::json($client));
        self::assertSame([], $this->em()->getRepository(User::class)->findBy(['email' => $dados['email']]));
    }

    private function pendenteDeConfirmacao(string $token): User
    {
        $user = $this->usuario('confirma_' . uniqid() . '@adv.com');
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->em()->persist(new ConfirmacaoEmail($user, ConfirmacaoEmail::hashDoToken($token), new \DateTimeImmutable('+6 days')));
        $this->em()->flush();

        return $user;
    }

    /** O e-mail que o cadastro mandou, com o texto da spec cadastro-senha-no-link. */
    #[TestDox('O e-mail de confirmação pede para confirmar e criar a senha, com o botão "Confirmar e criar minha senha"')]
    public function testEmailDeConfirmacaoPedeASenha(): void
    {
        $client = static::createClient();
        $this->prepararCsrf($client);

        $client->jsonRequest('POST', '/cadastro/criar', self::dados());

        self::assertResponseIsSuccessful();
        $html = (string) self::getMailerMessage()?->getHtmlBody();
        $texto = (string) preg_replace('/\s+/u', ' ', strip_tags($html));
        self::assertStringContainsString('Sua conta no BlueJus foi criada. Para ativá-la, confirme que este e-mail é seu e crie a sua senha. O link vale por 7 dias.', $texto);
        self::assertSame(1, preg_match('#<a href="[^"]*/cadastro/confirmar-email/[a-f0-9]{64}"[^>]*>Confirmar e criar minha senha</a>#', $html));
        self::assertStringNotContainsString('já pode usar', $texto);
        self::assertStringContainsString('Se você não criou esta conta, ignore este e-mail.', $texto);
    }

    /** Abre o link e devolve o formulário "Crie sua senha" preenchido. */
    private function formularioDoLink(KernelBrowser $client, string $token, string $senha, ?string $confirmacao = null, bool $aceite = true): Form
    {
        $form = $client->request('GET', '/cadastro/confirmar-email/' . $token)->selectButton('Criar senha e confirmar')->form();
        $form['senha'] = $senha;
        $form['confirmacao'] = $confirmacao ?? $senha;
        if ($aceite) {
            $form['aceite']->tick();
        }

        return $form;
    }

    private function senhaValida(int $id, string $senha): bool
    {
        $this->em()->clear();
        $user = $this->em()->find(User::class, $id);
        self::assertNotNull($user);

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $senha);
    }

    /** Nada mudou: e-mail a confirmar, senha antiga ('x') e o link sem a marca de uso. */
    private function assertNadaMudou(int $id): void
    {
        self::assertTrue($this->senhaValida($id, 'x'));
        $user = $this->em()->find(User::class, $id);
        self::assertFalse($user?->isEmailConfirmado());
        self::assertNull($this->em()->getRepository(ConfirmacaoEmail::class)->findOneBy(['user' => $user])?->getUsadoEm());
    }

    #[TestDox('O GET do link não confirma nada: mostra "Crie sua senha" com a régua, sem Referer nem cache')]
    public function testGetDoLinkNaoConfirma(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();

        $tela = $client->request('GET', '/cadastro/confirmar-email/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextSame('.confirmacao-email h3', 'Crie sua senha');
        $form = $tela->filter('.confirmacao-email > form.criar-senha[method="post"][action="/cadastro/confirmar-email/' . $token . '"]');
        self::assertCount(1, $form);
        self::assertSame('Nova senha', trim($form->filter('label[for="criarSenhaNova"]')->text()));
        self::assertSame('Mínimo de 8 caracteres', $form->filter('input#criarSenhaNova[name="senha"][type="password"]')->attr('placeholder'));
        self::assertCount(1, $form->filter('[data-forca="criarSenhaNova"] .criar-senha__barra'));
        self::assertSame('Confirmar nova senha', trim($form->filter('label[for="criarSenhaConf"]')->text()));
        self::assertCount(1, $form->filter('input#criarSenhaConf[name="confirmacao"][type="password"]'));
        self::assertCount(1, $form->filter('input[type="hidden"][name="_token"]'));
        self::assertSame('Criar senha e confirmar', trim($form->filter('button[type="submit"]')->text()));
        self::assertCount(1, $tela->filter('script[src*="/js/auth/criar-senha.js"]'));
        self::assertCount(0, $tela->filter('.confirmacao-email a[href="/login"]'));
        $this->assertNadaMudou($id);
    }

    #[TestDox('Criar a senha confirma o e-mail, marca o link e grava a senha nova; não entra; o segundo clique só diz que está confirmado')]
    public function testCriarSenhaConfirma(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();

        $client->submit($this->formularioDoLink($client, $token, 'novaSenha456'));

        self::assertResponseIsSuccessful();
        self::assertSame('E-mail confirmado. Sua senha foi criada.', trim((string) preg_replace('/\s+/u', ' ', $client->getCrawler()->filter('.confirmacao-email > .text-center')->text())));
        self::assertSelectorExists('.confirmacao-email a[href="/login"]');
        self::assertSelectorNotExists('form.criar-senha');
        self::assertNull($client->getContainer()->get('security.token_storage')->getToken(), 'não faz login sozinho');
        self::assertTrue($this->senhaValida($id, 'novaSenha456'));
        self::assertFalse($this->senhaValida($id, 'x'));
        $user = $this->em()->find(User::class, $id);
        self::assertTrue($user?->isEmailConfirmado());
        self::assertNotNull($this->em()->getRepository(ConfirmacaoEmail::class)->findOneBy(['user' => $user])?->getUsadoEm());

        $client->request('GET', '/cadastro/confirmar-email/' . $token);

        self::assertSelectorTextSame('.confirmacao-email h3', 'E-mail confirmado.');
        self::assertSelectorTextNotContains('.confirmacao-email', 'Sua senha foi criada.');
        self::assertSelectorNotExists('form.criar-senha');
    }

    #[TestDox('Dois POSTs do mesmo link: o segundo recebe "E-mail confirmado." e não troca a senha criada pelo primeiro')]
    public function testSegundoPostNaoTrocaASenha(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();
        $primeiro = $this->formularioDoLink($client, $token, 'primeiraSenha1');
        $segundo = $this->formularioDoLink($client, $token, 'segundaSenha2');

        $client->submit($primeiro);
        self::assertSelectorTextContains('.confirmacao-email', 'Sua senha foi criada.');
        $client->submit($segundo);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.confirmacao-email h3', 'E-mail confirmado.');
        self::assertSelectorTextNotContains('.confirmacao-email', 'Sua senha foi criada.');
        self::assertTrue($this->senhaValida($id, 'primeiraSenha1'));
        self::assertFalse($this->senhaValida($id, 'segundaSenha2'));
    }

    #[TestDox('A página "Crie sua senha" tem a caixa do cadastro, desmarcada, com os links dos Termos e da Política')]
    public function testPaginaTemACaixaDeAceite(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $this->pendenteDeConfirmacao($token);

        $tela = $client->request('GET', '/cadastro/confirmar-email/' . $token);

        $caixa = $tela->filter('form.criar-senha > .form-check');
        self::assertCount(1, $caixa->filter('input#criarSenhaAceite[type="checkbox"][name="aceite"]'));
        self::assertNull($caixa->filter('input#criarSenhaAceite')->attr('checked'));
        self::assertSame('Li e aceito os Termos de uso e a Política de privacidade.', trim((string) preg_replace('/\s+/u', ' ', $caixa->filter('label[for="criarSenhaAceite"]')->text())));
        self::assertSame('https://www.bluejus.com.br/termos/termos-de-uso.pdf', $caixa->filter('label a')->eq(0)->attr('href'));
        self::assertSame('Termos de uso', $caixa->filter('label a')->eq(0)->text());
        self::assertSame('https://www.bluejus.com.br/politica-de-privacidade', $caixa->filter('label a')->eq(1)->attr('href'));
        self::assertSame('Política de privacidade', $caixa->filter('label a')->eq(1)->text());
        self::assertSame('_blank', $caixa->filter('label a')->eq(0)->attr('target'));
    }

    /** @return list<string> as origens gravadas para a conta na tabela, em ordem */
    private function origens(string $tabela, int $id): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT origem FROM ' . $tabela . ' WHERE user_id = :id ORDER BY id', ['id' => $id]);
    }

    #[TestDox('POST sem a caixa: 422 com a mensagem do cadastro, o formulário volta, o link vale e nenhum aceite é gravado')]
    public function testSemAceiteRecusa(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();

        $client->submit($this->formularioDoLink($client, $token, 'novaSenha456', aceite: false));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('.criar-senha__erro', 'É preciso aceitar os Termos e a Política de privacidade.');
        self::assertSelectorExists('form.criar-senha input#criarSenhaAceite');
        $this->assertNadaMudou($id);
        self::assertSame([], $this->origens('aceite_termo', $id));
        self::assertSame([], $this->origens('ciencia_politica', $id));
    }

    #[TestDox('POST com a caixa: grava um aceite novo dos Termos e a ciência da Política (versão vigente, IP, user agent, escritório do dono) e os do cadastro ficam')]
    public function testComAceiteGravaOAceiteDoLink(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $user = $this->pendenteDeConfirmacao($token);
        $id = (int) $user->getId();
        $tenant = (new Tenant())->setName('Escritório da Ana ' . uniqid())->setCriadoPor($user);
        $this->em()->persist($tenant);
        $this->em()->persist(new AceiteTermo($user, TermoVigente::VERSAO, '198.51.100.1', 'Navegador do cadastro', $tenant));
        $this->em()->persist(new CienciaPolitica($user, PoliticaPrivacidadeVigente::VERSAO, '198.51.100.1', 'Navegador do cadastro'));
        $this->em()->flush();
        $form = $this->formularioDoLink($client, $token, 'novaSenha456');

        $client->request('POST', $form->getUri(), $form->getPhpValues(), [], ['REMOTE_ADDR' => '203.0.113.50', 'HTTP_USER_AGENT' => 'Navegador do link']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.confirmacao-email', 'Sua senha foi criada.');
        self::assertSame(['cadastro', 'link_confirmacao'], $this->origens('aceite_termo', $id));
        self::assertSame(['cadastro', 'link_confirmacao'], $this->origens('ciencia_politica', $id));
        $conexao = $this->em()->getConnection();
        $aceite = $conexao->fetchAssociative("SELECT versao, ip, user_agent, tenant_id FROM aceite_termo WHERE user_id = :id AND origem = 'link_confirmacao'", ['id' => $id]);
        self::assertSame(['versao' => TermoVigente::VERSAO, 'ip' => '203.0.113.50', 'user_agent' => 'Navegador do link', 'tenant_id' => $tenant->getId()], $aceite);
        $ciencia = $conexao->fetchAssociative("SELECT versao, ip, user_agent FROM ciencia_politica WHERE user_id = :id AND origem = 'link_confirmacao'", ['id' => $id]);
        self::assertSame(['versao' => PoliticaPrivacidadeVigente::VERSAO, 'ip' => '203.0.113.50', 'user_agent' => 'Navegador do link'], $ciencia);
        self::assertSame('198.51.100.1', $conexao->fetchOne("SELECT ip FROM aceite_termo WHERE user_id = :id AND origem = 'cadastro'", ['id' => $id]));
    }

    #[TestDox('POST sem o CSRF certo: 403, o formulário volta com o aviso e nada muda')]
    public function testCriarSenhaSemCsrf(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();
        $client->request('GET', '/cadastro/confirmar-email/' . $token);

        $client->request('POST', '/cadastro/confirmar-email/' . $token, ['senha' => 'novaSenha456', 'confirmacao' => 'novaSenha456', '_token' => 'forjado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('.criar-senha__erro', 'Sessão expirada. Recarregue a página.');
        $this->assertNadaMudou($id);

        $client->request('POST', '/cadastro/confirmar-email/' . $token, ['senha' => 'novaSenha456', 'confirmacao' => 'novaSenha456']);

        self::assertResponseStatusCodeSame(403);
        $this->assertNadaMudou($id);
    }

    #[TestDox('CSRF inválido num link que não vale: 403 com a página de erro, sem o formulário')]
    public function testCsrfInvalidoEmLinkQueNaoVale(): void
    {
        $client = static::createClient();

        $client->request('POST', '/cadastro/confirmar-email/' . bin2hex(random_bytes(32)), ['senha' => 'novaSenha456', 'confirmacao' => 'novaSenha456', '_token' => 'forjado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('body', 'Este link de confirmação é inválido ou expirou.');
        self::assertSelectorNotExists('form');
    }

    #[TestDox('CSRF inválido com o e-mail já confirmado: 403 com "E-mail confirmado.", sem o formulário e sem trocar a senha')]
    public function testCsrfInvalidoComEmailJaConfirmado(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $user = $this->pendenteDeConfirmacao($token);
        $user->confirmarEmail();
        $this->em()->flush();

        $client->request('POST', '/cadastro/confirmar-email/' . $token, ['senha' => 'novaSenha456', 'confirmacao' => 'novaSenha456', '_token' => 'forjado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h3', 'E-mail confirmado.');
        self::assertSelectorNotExists('form');
        self::assertTrue($this->senhaValida((int) $user->getId(), 'x'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function senhasRecusadas(): iterable
    {
        yield 'fraca' => ['abcdefgh', 'abcdefgh', ForcaDaSenha::ERRO];
        yield 'acima do máximo' => ['Ab1' . str_repeat('x', ForcaDaSenha::MAXIMO_EM_BYTES), 'Ab1' . str_repeat('x', ForcaDaSenha::MAXIMO_EM_BYTES), ForcaDaSenha::ERRO];
        yield 'confirmação diferente' => ['novaSenha456', 'novaSenha457', 'As senhas não conferem.'];
    }

    #[DataProvider('senhasRecusadas')]
    #[TestDox('Senha recusada ($mensagem): 422, o formulário volta com a mensagem e o link continua valendo')]
    public function testSenhaRecusadaNaoConsomeOLink(string $senha, string $confirmacao, string $mensagem): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();

        $client->submit($this->formularioDoLink($client, $token, $senha, $confirmacao));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('.criar-senha__erro', $mensagem);
        self::assertSelectorExists('form.criar-senha');
        $this->assertNadaMudou($id);

        $client->submit($this->formularioDoLink($client, $token, 'novaSenha456'));

        self::assertSelectorTextContains('.confirmacao-email', 'Sua senha foi criada.');
    }

    #[TestDox('POST num link desconhecido mostra o erro do link e não grava nada')]
    public function testCriarSenhaLinkInvalido(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(32));
        $id = (int) $this->pendenteDeConfirmacao($token)->getId();
        $form = $this->formularioDoLink($client, $token, 'novaSenha456');

        $client->request('POST', '/cadastro/confirmar-email/' . bin2hex(random_bytes(32)), $form->getValues());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.confirmacao-email', 'Este link de confirmação é inválido ou expirou.');
        self::assertSelectorNotExists('form.criar-senha');
        $this->assertNadaMudou($id);
    }

    #[TestDox('Link de confirmação desconhecido mostra o erro sem confirmar nada')]
    public function testConfirmarEmailInvalido(): void
    {
        $client = static::createClient();
        $id = $this->pendenteDeConfirmacao(bin2hex(random_bytes(32)))->getId();

        $client->request('GET', '/cadastro/confirmar-email/' . bin2hex(random_bytes(32)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.confirmacao-email', 'Este link de confirmação é inválido ou expirou.');
        $this->em()->clear();
        self::assertFalse($this->em()->find(User::class, $id)?->isEmailConfirmado());
    }

    #[TestDox('Token fora do formato não chega ao caso de uso: 404')]
    public function testConfirmarEmailFormatoInvalido(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cadastro/confirmar-email/nao-e-token');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('GET /cadastro/confirmar/{token} antigo cria conta ativa + escritório (dono) e redireciona ao login')]
    public function testConfirmarCriaContaEEscritorio(): void
    {
        $client   = static::createClient();
        $email    = 'nova_' . uniqid() . '@adv.com';
        $cadastro = $this->criarPendente($email);
        $nomeEsc  = $cadastro->getNomeEscritorio();

        $client->request('GET', '/cadastro/confirmar/' . $cadastro->getToken());

        self::assertResponseRedirects('/login');

        $em   = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertSame('12345', $user->getOabNumero());

        $tenant = $em->getRepository(Tenant::class)->findOneBy(['name' => $nomeEsc]);
        self::assertNotNull($tenant);
        self::assertSame($user->getId(), $tenant->getCriadoPor()?->getId());

        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        self::assertTrue($vinculo->isActive());

        $atualizado = $em->getRepository(CadastroPendente::class)->find($cadastro->getId());
        self::assertSame('confirmado', $atualizado->getStatus());
    }

    #[TestDox('GET /cadastro/confirmar com token inválido mostra a página de erro (não redireciona)')]
    public function testConfirmarTokenInvalido(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cadastro/confirmar/token-invalido-xyz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível confirmar');
    }

    #[TestDox('Usuário logado em /cadastro/confirmar é redirecionado e não cria conta nova (I1)')]
    public function testConfirmarBloqueadoParaUsuarioLogado(): void
    {
        $client   = static::createClient();
        $emailPen = 'pend_' . uniqid() . '@adv.com';
        $cadastro = $this->criarPendente($emailPen);

        $this->logado($client);
        $client->request('GET', '/cadastro/confirmar/' . $cadastro->getToken());

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $emailPen]));
        self::assertSame('pending', $this->em()->getRepository(CadastroPendente::class)->find($cadastro->getId())->getStatus());
    }
}
