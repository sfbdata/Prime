<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Entity\AjusteEntrada;
use App\Controller\SecurityController;
use App\Entity\Auth\User;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tela de entrada do desenho "10 - Login - VS 19.10.26" (spec `docs/specs/login-desenho-10.md`).
 *
 * O que estes testes protegem, por ordem de importância:
 *
 * 1. **o contrato do formulário** (tela-login-redesenho.md): `email`, `password`, `_csrf_token`,
 *    `_remember_me`, botão "Entrar" e um único `a[href="/senha/esqueci"]`. O login pelo formulário
 *    renderizado tem que terminar autenticado;
 * 2. **os textos do desenho**, que o dono pediu exatos;
 * 3. **quem vê o quê**: anônimo não recebe ferramenta nem token de Master, nem link oculto; o Master
 *    logado vê a tela com as ferramentas; outro logado é redirecionado.
 *
 * O que NENHUM teste aqui vê: posição, cor, tamanho e animação. Isso é smoke na tela, do dono.
 */
#[CoversClass(SecurityController::class)]
final class LoginTelaTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-de-teste-123';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $papeis */
    private function criarUsuario(array $papeis = ['ROLE_USER'], string $nome = 'Dra. Entrada'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('login_' . uniqid() . '@adv.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA));

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function ajustar(string $chave, ?string $valor, bool $oculto, User $por): void
    {
        $ajuste = new AjusteEntrada($chave);
        $agora = new \DateTimeImmutable();
        $ajuste->definirValor($valor, $por, $agora);
        $ajuste->definirOculto($oculto, $por, $agora);
        $this->em()->persist($ajuste);
        $this->em()->flush();
    }

    private function entrarComoMaster(KernelBrowser $client): User
    {
        $master = $this->criarUsuario(['ROLE_SUPER_ADMIN'], 'Farlei Assis da Rocha');
        $client->loginUser($master);
        $this->marcarTermosAceitos($client);

        return $master;
    }

    private function enviarLogin(KernelBrowser $client, string $email, string $senha): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Entrar')->form();
        $form['email'] = $email;
        $form['password'] = $senha;
        $client->submit($form);
    }

    #[TestDox('O CSS da tela de entrada estiliza as chaves de flash que o sistema grava (sucesso e erro)')]
    public function testCssDoFlashCobreAsChavesDoSistema(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../public/css/auth/entrada.css');

        self::assertStringContainsString('.lg-flash--sucesso', $css);
        self::assertStringContainsString('.lg-flash--erro', $css);
    }

    #[TestDox('O login continua funcionando pelo formulário que a tela renderiza')]
    public function testLoginRealPeloFormulario(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario();

        $this->enviarLogin($client, (string) $user->getEmail(), self::SENHA);

        self::assertResponseRedirects(null, null, 'Credencial correta tem que sair do /login. Se ficou, o contrato do formulário quebrou.');
        $destino = (string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH);
        self::assertSame('/login/entrando', $destino, 'Voltar ao /login é o sintoma de CSRF ausente ou campo com nome errado.');
        self::assertNotNull($client->getContainer()->get('security.token_storage')->getToken(), 'A sessão precisa terminar autenticada.');

        // A volta pós-login passa pelo véu `/login/entrando`: seguir o redirect não pode cair de novo no formulário.
        $client->followRedirect();
        self::assertLessThan(400, $client->getResponse()->getStatusCode());
        self::assertSelectorNotExists('form#lgForm');
    }

    #[TestDox('Contrato do formulário: campos, token CSRF, botão "Entrar" e um só link de recuperação')]
    public function testContratoDoFormulario(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form#lgForm');
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('/login', $form->attr('action'));
        self::assertCount(1, $crawler->filter('form input[name="email"][type="email"][autocomplete="username"]'));
        self::assertCount(1, $crawler->filter('form input[name="password"][type="password"]'));
        self::assertCount(1, $crawler->filter('form input[name="_csrf_token"]'));
        self::assertCount(1, $crawler->filter('form input[name="_remember_me"][type="checkbox"]'));
        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
        self::assertCount(1, $crawler->filter('a[href="/cadastro"]'));

        // O primeiro botão "Entrar" da página é o envio do formulário de login (o painel tem "Entrar com segurança").
        $entrar = $crawler->selectButton('Entrar')->first();
        self::assertSame('submit', $entrar->attr('type'));
        self::assertSame('lgEntrar', $entrar->attr('id'));
        self::assertCount(1, $crawler->filter('form#lgForm button#lgEntrar > i.bi-box-arrow-in-right'));
    }

    #[TestDox('Credencial errada volta com o erro do servidor na linha de erro do desenho')]
    public function testErroDeCredencialApareceNaTela(): void
    {
        $client = static::createClient();
        $user = $this->criarUsuario();

        $this->enviarLogin($client, (string) $user->getEmail(), 'senha-errada');
        $crawler = $client->followRedirect();

        $erro = $crawler->filter('form#lgForm > .lg-cartao__corpo > #lgErro[role="alert"]');
        self::assertCount(1, $erro, 'O erro precisa vir do servidor, não só do JS.');
        self::assertNull($erro->attr('hidden'));
        self::assertNotSame('', trim($erro->text()));
        self::assertCount(2, $crawler->filter('form input[aria-invalid="true"]'));
        self::assertSame((string) $user->getEmail(), $crawler->filter('#lgEmail')->attr('value'));
    }

    #[TestDox('Sem erro, a linha de erro existe (para a validação do cliente) mas nasce escondida')]
    public function testLinhaDeErroNasceEscondida(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSame('', $crawler->filter('#lgErro')->attr('hidden'));
    }

    /** @return iterable<string, array{string}> */
    public static function textosDoDesenho(): iterable
    {
        $textos = [
            // tarja e cartão
            'Conexão segura', 'Criptografia de ponta a ponta', 'Bem-vindo de volta', 'Acesse sua conta para continuar',
            'E-mail', 'Senha', 'Caps Lock está ativado.', 'Manter conectado', 'Esqueceu a senha?', 'Entrar',
            'O BlueJus não tem acesso aos seus arquivos nem ao conteúdo das pastas.',
            'Não tem conta?', 'Criar minha conta e escritório',
            // painel Segurança e privacidade
            'SEGURANÇA E PRIVACIDADE', 'O sigilo do seu escritório começa aqui.',
            'Advocacia é confiança. O BlueJus foi construído para que as informações dos seus clientes fiquem só com quem tem direito a elas: você e a equipe que você autorizar.',
            'Documentos, registros e conversas são cifrados antes de sair do seu aparelho e só são abertos por quem o escritório autorizou. Nem a equipe BlueJus tem acesso ao conteúdo das suas pastas.',
            'Nenhum terceiro vê os seus dados',
            'Não vendemos, não compartilhamos e não usamos as informações do escritório para publicidade. Cada conta fica isolada das demais.',
            'Você controla quem acessa',
            'O usuário primário define permissões, oculta abas e tranca pastas sigilosas. Cada acesso e alteração fica registrado com data, hora e autor.',
            'Conexão protegida',
            'Todo o tráfego usa HTTPS com certificado válido, e as sessões de aparelhos sem uso são encerradas automaticamente.',
            'Em conformidade com a LGPD (Lei nº 13.709/2018)',
            'O escritório é o controlador; o BlueJus atua como operador.',
            'Dados usados só para prestar o serviço contratado.',
            'Direitos do titular: acesso, correção, portabilidade e exclusão.',
            'Incidentes comunicados ao escritório e à ANPD.',
            'Encarregado de dados (DPO) disponível.',
            'Exportação completa dos seus dados quando quiser.',
            'Segurança que ajuda o escritório a crescer',
            'Mostrar ao cliente que os dados dele estão protegidos é diferencial na hora de fechar contrato. Com o BlueJus você atende a LGPD e transmite profissionalismo desde o primeiro atendimento.',
            'Entrar com segurança', 'Política de privacidade', 'Termos de uso', 'Falar com o encarregado de dados',
            // modal
            'Informe o e-mail da sua conta. Enviaremos um código de 6 números para criar uma nova senha.',
            'Ele vale por 15 minutos.', 'Código', 'Nova senha', 'Confirmar nova senha', 'Reenviar código',
            'Senha alterada', 'Entre com a nova senha. Por segurança, as outras sessões foram encerradas.',
            'Crie seu escritório digital em 1 minuto. Você entra como usuário primário e depois convida a sua equipe.',
            'Seu nome completo', 'Nome do escritório', 'Li e aceito os Termos de uso e a Política de privacidade.',
            'Já tem conta? Entrar', 'Abrindo o seu escritório digital…',
            // rodapé e suporte
            'BlueJus', 'Termos', 'Privacidade', 'Falar com suporte', 'Suporte BlueJus', 'Sua mensagem vai direto para a equipe',
            'Não consigo entrar', 'Esqueci a senha', 'Criar conta', 'Outro assunto', 'Enviar para o suporte',
            'Não envie senhas nem dados de clientes por aqui.', 'Mensagem enviada', 'A equipe BlueJus recebeu no chat e vai responder no e-mail',
        ];
        foreach ($textos as $texto) {
            yield $texto => [$texto];
        }
    }

    #[TestDox('Texto do desenho na página: $texto')]
    #[DataProvider('textosDoDesenho')]
    public function testTextoDoDesenho(string $texto): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertStringContainsString($texto, $crawler->filter('body')->text());
    }

    #[TestDox('Placeholders do desenho nos campos')]
    public function testPlaceholdersDoDesenho(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSame('nome@escritorio.com.br', $crawler->filter('#lgEmail')->attr('placeholder'));
        self::assertSame('000000', $crawler->filter('#lgRsCod')->attr('placeholder'));
        self::assertSame('one-time-code', $crawler->filter('#lgRsCod')->attr('autocomplete'));
        self::assertSame('Mínimo de 8 caracteres', $crawler->filter('#lgRsNova')->attr('placeholder'));
        self::assertSame('Ex.: BlueJus Advogados Associados', $crawler->filter('#lgCdEsc')->attr('placeholder'));
        self::assertSame('Seu nome', $crawler->filter('#lgSupNome')->attr('placeholder'));
        self::assertSame('E-mail para resposta', $crawler->filter('#lgSupEmail')->attr('placeholder'));
        self::assertSame('Como podemos ajudar?', $crawler->filter('#lgSupMsg')->attr('placeholder'));
    }

    #[TestDox('O passo "Criar conta" não pede senha nem mostra a régua (D1 da spec cadastro-senha-no-link)')]
    public function testCadastroSemCampoDeSenha(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $cad = $crawler->filter('#lgMdForm > .lg-md__passo[data-passo="cad"]');
        self::assertCount(1, $cad);
        self::assertCount(1, $cad->filter('#lgCdEmail'));
        self::assertCount(1, $cad->filter('#lgCdAceite'));
        self::assertCount(0, $cad->filter('input[type="password"]'));
        self::assertCount(0, $cad->filter('.lg-forca'));
        self::assertCount(0, $crawler->filter('#lgCdSenha'));
    }

    #[TestDox('Arranjo do desenho: tarja, cartão, painel e rodapé nos lugares certos')]
    public function testArranjoDoDesenho(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('#lgRaiz > header.lg-tarja > .lg-tarja__logo > img[src="/images/logo/bluejus-logo-branco-completo.png"]'));
        self::assertCount(1, $crawler->filter('#lgRaiz > header.lg-tarja > #lgFrase'));
        self::assertCount(1, $crawler->filter('#lgRaiz > header.lg-tarja > button[data-seg-abrir] > i.bi-chevron-right'));
        self::assertCount(1, $crawler->filter('#lgRaiz > .lg-ceu'));
        self::assertCount(1, $crawler->filter('#lgRaiz > main.lg-main > #lgCartao > form#lgForm > .lg-cartao__topo > .lg-cartao__logo > img[src="/images/logo/bluejus-logo-cor-completo.png"]'));
        self::assertCount(1, $crawler->filter('form#lgForm > .lg-cartao__corpo > .lg-linha > a[href="/senha/esqueci"]'));
        self::assertCount(1, $crawler->filter('form#lgForm > .lg-cartao__corpo > .lg-linha > .lg-manter > input[name="_remember_me"]'));
        self::assertCount(1, $crawler->filter('form#lgForm > .lg-cartao__corpo > button.lg-selo[data-seg-abrir]'));
        self::assertCount(1, $crawler->filter('form#lgForm .lg-senha > input[name="password"] + button#lgOlho[type="button"][title="Mostrar senha"]'));
        self::assertCount(4, $crawler->filter('#lgSeg .lg-seg__corpo > .lg-seg__item'));
        self::assertCount(6, $crawler->filter('#lgSeg .lg-seg__corpo > .lg-lgpd > .lg-lgpd__grade > span'));
        // rs1, rs2, rs3, cad e o "Confirme seu e-mail" depois do cadastro (spec cadastro-exige-confirmacao).
        self::assertCount(5, $crawler->filter('#lgMd #lgMdForm > .lg-md__passo[data-passo]'));
        self::assertCount(1, $crawler->filter('#lgRaiz > footer.lg-rodape > #lgSupBtn'));
        self::assertCount(4, $crawler->filter('#lgRaiz > footer.lg-rodape > #lgSup > #lgSupForm > .lg-sup__chips > button[data-assunto]'));
        self::assertCount(1, $crawler->filter('#lgSupForm > .lg-pote > input[name="site"]'));
        self::assertCount(1, $crawler->filter('#lgRaiz > #lgVeu > .lg-veu__anel > img[src="/images/logo/bluejus-simbolo-branco.png"]'));
        // A caixa do protótipo com o código de teste não entra (spec, itens fora do produto).
        self::assertStringNotContainsString('Protótipo', $crawler->filter('body')->text());
    }

    #[TestDox('Painel de segurança: Política e Termos vêm dos ajustes; encarregado por e-mail')]
    public function testLinksDoPainelDeSeguranca(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $links = $crawler->filter('#lgSeg .lg-seg__links > a');
        self::assertCount(3, $links);
        self::assertSame('https://www.bluejus.com.br/politica-de-privacidade', $links->eq(0)->attr('href'));
        self::assertSame('https://www.bluejus.com.br/termos/termos-de-uso.pdf', $links->eq(1)->attr('href'));
        self::assertSame('mailto:bluejus.86@gmail.com?subject=Encarregado%20de%20dados%20(LGPD)', $links->eq(2)->attr('href'));
        self::assertCount(2, $crawler->filter('#lgMd .lg-aceite a[target="_blank"][rel="noopener"]'));
    }

    #[TestDox('A frase da tarja é uma das quatro do desenho')]
    public function testFraseDoDia(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $frase = trim($crawler->filter('#lgFrase')->text());
        self::assertContains($frase, SecurityController::FRASES);
        // Palavra a palavra (lgPal), a primeira com 0,9 s de atraso.
        $palavras = $crawler->filter('#lgFrase > span.lg-pal');
        self::assertCount(count(explode(' ', $frase)), $palavras);
        self::assertSame('animation-delay:0.90s', $palavras->first()->attr('style'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function passosAbriveis(): iterable
    {
        yield 'senha' => ['/login?abrir=senha', 'senha'];
        yield 'cadastro' => ['/login?abrir=cadastro', 'cadastro'];
        yield 'valor estranho' => ['/login?abrir=javascript', ''];
        yield 'sem parâmetro' => ['/login', ''];
        yield 'lista no lugar de texto' => ['/login?abrir[]=senha', ''];
    }

    #[TestDox('?abrir= chega ao HTML só como "senha" ou "cadastro"')]
    #[DataProvider('passosAbriveis')]
    public function testAbrirChegaAoHtml(string $url, string $esperado): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame($esperado, $crawler->filter('#lgRaiz')->attr('data-abrir'));
    }

    #[TestDox('Anônimo recebe os tokens públicos e nenhuma ferramenta nem token de Master')]
    public function testAnonimoNaoRecebeFerramentasDeMaster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');
        $raiz = $crawler->filter('#lgRaiz');

        self::assertNotSame('', (string) $raiz->attr('data-token-senha'));
        self::assertNotSame('', (string) $raiz->attr('data-token-cadastro'));
        self::assertNotSame('', (string) $raiz->attr('data-token-suporte'));
        self::assertNull($raiz->attr('data-master'));
        self::assertNull($raiz->attr('data-token-ajuste'));
        self::assertNull($raiz->attr('data-links'));
        self::assertCount(0, $crawler->filter('#lgLk'));
        self::assertCount(0, $crawler->filter('#lgDlgTg'));
        self::assertCount(0, $crawler->filter('#lgDlg'));
        self::assertStringNotContainsString('SÓ MASTER', $crawler->filter('body')->text());
    }

    #[TestDox('Rodapé do anônimo: Termos e Privacidade do desenho; redes vazias não aparecem')]
    public function testRodapePadraoDoAnonimo(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $links = $crawler->filter('footer.lg-rodape > #lgLinks > a');
        self::assertCount(2, $links);
        self::assertSame('Termos', trim($links->eq(0)->text()));
        self::assertSame('https://www.bluejus.com.br/termos/termos-de-uso.pdf', $links->eq(0)->attr('href'));
        self::assertSame('Termos', $links->eq(0)->attr('title'));
        self::assertSame('_blank', $links->eq(0)->attr('target'));
        self::assertSame('noopener', $links->eq(0)->attr('rel'));
        self::assertSame('Privacidade', trim($links->eq(1)->text()));
        self::assertSame('https://www.bluejus.com.br/politica-de-privacidade', $links->eq(1)->attr('href'));
        self::assertCount(1, $crawler->filter('footer.lg-rodape > #lgLinks > span'), 'Um "·" entre Termos e Privacidade.');
    }

    #[TestDox('Link oculto pelo Master some para o anônimo; redes com endereço aparecem como ícone')]
    public function testLinkOcultoNaoApareceParaAnonimo(): void
    {
        $client = static::createClient();
        $master = $this->criarUsuario(['ROLE_SUPER_ADMIN'], 'Master Teste');
        $this->ajustar(AjusteEntrada::chaveDoLink('termos'), null, true, $master);
        $this->ajustar(AjusteEntrada::chaveDoLink('linkedin'), 'https://www.linkedin.com/company/bluejus', false, $master);
        $this->ajustar(AjusteEntrada::chaveDoLink('instagram'), 'https://www.instagram.com/bluejus', false, $master);

        $crawler = $client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('#lgLinks > a[data-chave="termos"]'));
        self::assertCount(1, $crawler->filter('#lgLinks > a[data-chave="priv"]'));
        self::assertCount(1, $crawler->filter('#lgLinks > a[data-chave="linkedin"][href="https://www.linkedin.com/company/bluejus"] > i.bi-linkedin'));
        self::assertCount(1, $crawler->filter('#lgLinks > a[data-chave="instagram"] > i.bi-instagram'));
        // Separador do desenho: "·" entre Privacidade e LinkedIn, nenhum entre dois ícones.
        self::assertCount(1, $crawler->filter('#lgLinks > span'));
        // O painel e o cadastro continuam apontando para os Termos, mesmo com o link oculto no rodapé.
        self::assertCount(1, $crawler->filter('#lgSeg .lg-seg__links > a[href="https://www.bluejus.com.br/termos/termos-de-uso.pdf"]'));
    }

    #[TestDox('O Master logado vê a tela com as ferramentas SÓ MASTER, inclusive o link oculto')]
    public function testMasterLogadoVeFerramentas(): void
    {
        $client = static::createClient();
        $master = $this->entrarComoMaster($client);
        $this->ajustar(AjusteEntrada::chaveDoLink('termos'), null, true, $master);

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $raiz = $crawler->filter('#lgRaiz');
        self::assertSame('1', $raiz->attr('data-master'));
        self::assertNotSame('', (string) $raiz->attr('data-token-ajuste'));
        $links = json_decode((string) $raiz->attr('data-links'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['termos', 'priv', 'linkedin', 'instagram'], array_column($links, 'chave'));

        self::assertCount(4, $crawler->filter('#lgLinks > a'), 'O Master vê todos os links, até o oculto e os vazios.');
        self::assertSame('Termos · botão direito para editar', $crawler->filter('#lgLinks > a[data-chave="termos"]')->attr('title'));
        self::assertCount(1, $crawler->filter('#lgLk #lgLkForm > .lg-lk__botoes > .lg-lk__salvar'));
        self::assertCount(1, $crawler->filter('#lgSup > .lg-sup__topo > #lgDlgTg'));
        self::assertStringContainsString('QUEM RECEBE AS MENSAGENS DE SUPORTE · SÓ MASTER VÊ', $crawler->filter('#lgDlg')->text());
        self::assertGreaterThan(0, $crawler->filter(sprintf('#lgDlg .lg-dlg__op[data-user-id="%d"]', $master->getId()))->count());
        self::assertStringContainsString('recebe as mensagens no Chat I.A.', $crawler->filter('#lgDlgTxt')->text());
    }

    #[TestDox('Outro usuário logado não vê a tela: é redirecionado como antes')]
    public function testUsuarioComumLogadoERedirecionado(): void
    {
        $client = static::createClient();
        $client->loginUser($this->criarUsuario());
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    #[TestDox('O olho da senha e o aviso de Caps Lock')]
    public function testOlhoECapsLock(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $olho = $crawler->filter('button#lgOlho');
        self::assertSame('button', $olho->attr('type'), 'Precisa ser type=button: dentro do form, submeteria.');
        self::assertSame('Mostrar senha', $olho->attr('title'));
        self::assertSame('false', $olho->attr('aria-pressed'));
        self::assertCount(1, $olho->filter('i.bi-eye'));

        $caps = $crawler->filter('#lgCaps');
        self::assertSame('polite', $caps->attr('aria-live'));
        self::assertSame('', $caps->attr('hidden'));
        self::assertCount(1, $caps->filter('i.bi-capslock-fill'));
    }

    #[TestDox('O comportamento vem de arquivos próprios, sem dados em script inline')]
    public function testScriptEEstiloProprios(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('script[src*="/js/auth/entrada.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="/css/auth/entrada.css"]'));
        // O catálogo do idioma é dado (application/json), o navegador não executa (spec idioma-do-sistema §21.1).
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/json"])'));
        self::assertCount(1, $crawler->filter('script[type="application/json"][data-bj-t]'));
    }

    #[TestDox('A mensagem que chega de outra tela (flash) continua visível no login')]
    public function testFlashDeOutraTelaApareceNoLogin(): void
    {
        $client = static::createClient();

        // Caminho real: RecuperacaoSenhaController manda "Senha redefinida!" para cá.
        $sessao = static::getContainer()->get('session.factory')->createSession();
        $sessao->getFlashBag()->add('success', 'Senha redefinida! Entre com a senha nova.');
        $sessao->save();
        $client->getCookieJar()->set(new Cookie($sessao->getName(), $sessao->getId()));

        $crawler = $client->request('GET', '/login');

        self::assertStringContainsString('Senha redefinida!', $this->textoDe($crawler, '#lgRaiz > .lg-flash'));
    }

    private function textoDe(Crawler $crawler, string $seletor): string
    {
        $no = $crawler->filter($seletor);

        return $no->count() > 0 ? $no->text() : '';
    }
}
