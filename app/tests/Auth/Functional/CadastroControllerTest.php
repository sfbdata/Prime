<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroController;
use App\Auth\Entity\CadastroPendente;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\ForcaDaSenha;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    #[TestDox('E-mail já cadastrado, mesmo com outra caixa, devolve 422 e não cria escritório')]
    public function testEmailDuplicadoComOutraCaixa(): void
    {
        $client = static::createClient();
        $email = 'dup_' . uniqid() . '@adv.com';
        $this->usuario($email);
        $this->prepararCsrf($client);
        $dados = self::dados(['email' => strtoupper($email)]);

        $client->jsonRequest('POST', '/cadastro/criar', $dados);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            ['erro' => 'Já existe uma conta com este e-mail. Faça login para criar o escritório por dentro.'],
            self::json($client),
        );
        self::assertNull($this->em()->getRepository(Tenant::class)->findOneBy(['name' => $dados['escritorio']]));
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
    private function formularioDoLink(KernelBrowser $client, string $token, string $senha, ?string $confirmacao = null): Form
    {
        $form = $client->request('GET', '/cadastro/confirmar-email/' . $token)->selectButton('Criar senha e confirmar')->form();
        $form['senha'] = $senha;
        $form['confirmacao'] = $confirmacao ?? $senha;

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
