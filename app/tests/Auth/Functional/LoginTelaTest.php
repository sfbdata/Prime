<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Controller\SecurityController;
use App\Entity\Auth\User;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tela de entrada redesenhada a partir do modelo em `docs/tela_login/`.
 *
 * O que estes testes protegem, por ordem de importância:
 *
 * 1. **o contrato do formulário** — o modelo trazia `name="senha"`, sem `_csrf_token`
 *    e com `name="lembrar"`. Qualquer um dos três, sozinho, tranca todo mundo do lado
 *    de fora. `testLoginRealPeloFormulario` é o único teste aqui que prova isso ponta
 *    a ponta: preenche o formulário renderizado e exige a sessão autenticada no fim;
 * 2. **os elementos novos** — olho da senha, aviso de Caps Lock, suporte, rodapé;
 * 3. **a URL do suporte** — vazia, o botão continua na tela, mas não navega.
 *
 * O que NENHUM teste aqui vê: posição, cor, tamanho e a animação. Isso é smoke na
 * tela, do dono.
 */
#[CoversClass(SecurityController::class)]
final class LoginTelaTest extends JusPrimeWebTestCase
{
    private const SENHA = 'senha-de-teste-123';

    private function criarUsuario(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('login_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Entrada');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, self::SENHA));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    #[TestDox('O login continua funcionando pelo formulário que a tela renderiza')]
    public function testLoginRealPeloFormulario(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Entrar')->form();
        $form['email']    = $user->getEmail();
        $form['password'] = self::SENHA;
        $client->submit($form);

        self::assertResponseRedirects(
            null,
            null,
            'Credencial correta tem que sair do /login. Se ficou, o contrato do formulário quebrou.',
        );
        self::assertStringNotContainsString(
            '/login',
            (string) $client->getResponse()->headers->get('Location'),
            'Redirecionar de volta ao /login é o sintoma de CSRF ausente ou campo com nome errado.',
        );
        self::assertNotNull(
            $client->getContainer()->get('security.token_storage')->getToken(),
            'A sessão precisa terminar autenticada.',
        );
    }

    #[TestDox('O campo da senha se chama "password" e o formulário leva o token CSRF')]
    public function testContratoDoFormulario(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('form input[name="email"]'));
        self::assertCount(1, $crawler->filter('form input[name="password"][type="password"]'));
        self::assertCount(1, $crawler->filter('form input[name="_csrf_token"]'));
        self::assertCount(1, $crawler->filter('form input[name="_remember_me"][type="checkbox"]'));
        self::assertSame('post', strtolower((string) $crawler->filter('form')->attr('method')));
    }

    #[TestDox('Credencial errada volta para a tela com o erro e marca os campos como inválidos')]
    public function testErroDeCredencialApareceNaTela(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $crawler = $client->request('GET', '/login');
        $form    = $crawler->selectButton('Entrar')->form();
        $form['email']    = $user->getEmail();
        $form['password'] = 'senha-errada';
        $client->submit($form);

        $crawler = $client->followRedirect();

        self::assertCount(1, $crawler->filter('.cartao__erro[role="alert"]'), 'O erro precisa vir do servidor, não de JS.');
        self::assertCount(2, $crawler->filter('form input[aria-invalid="true"]'));
    }

    #[TestDox('O olho da senha existe e nasce anunciando "Mostrar senha"')]
    public function testOlhoDaSenha(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        $olho = $crawler->filter('button#olho');
        self::assertCount(1, $olho);
        self::assertSame('button', $olho->attr('type'), 'Precisa ser type=button: dentro do form, submeteria.');
        self::assertSame('Mostrar senha', $olho->attr('aria-label'));
        self::assertSame('false', $olho->attr('aria-pressed'));
    }

    #[TestDox('O aviso de Caps Lock é anunciável por leitor de tela')]
    public function testAvisoDeCapsLockTemAriaLive(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        $aviso = $crawler->filter('#capsAviso');
        self::assertCount(1, $aviso);
        // O modelo trazia um <p> mudo. Quem usa leitor de tela é justamente quem não vê
        // a luz do Caps Lock no teclado — sem aria-live o aviso não serve para nada.
        self::assertSame('polite', $aviso->attr('aria-live'));
    }

    #[TestDox('O rodapé traz os termos, a privacidade e as duas redes')]
    public function testRodapeLegalERedes(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('.login-rodape a[href="/termos/termos-de-uso.pdf"]'));
        self::assertCount(1, $crawler->filter('.login-rodape a[data-rede="linkedin"]'));
        self::assertCount(1, $crawler->filter('.login-rodape a[data-rede="instagram"]'));

        // "Todos os botões, mesmo que não levem a lugar nenhum": a privacidade é o
        // quarto link e o único sem destino previsto, então é o que some sem barulho.
        $rotulos = $crawler->filter('.login-rodape a')->each(fn ($a) => trim($a->text()));
        self::assertContains('Privacidade', $rotulos);
    }

    #[TestDox('Sem URL configurada o botão de suporte aparece, mas não navega')]
    public function testSuporteInerteSemUrl(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        // Em teste o parâmetro nasce vazio: o botão é do desenho, o destino é do dono.
        $sup = $crawler->filter('a#suporte');
        self::assertCount(1, $sup);
        self::assertSame('#', $sup->attr('href'));
        self::assertNull($sup->attr('target'), 'target=_blank com href="#" reabriria a própria tela.');
    }

    #[TestDox('Com URL configurada o suporte vira link externo, em nova aba e com rel de segurança')]
    public function testSuporteAtivoComUrlConfigurada(): void
    {
        // O parâmetro sai de `%env(...)%`, resolvido em tempo de execução: definir a
        // variável ANTES de subir o kernel é o que troca o valor sem tocar em config.
        $anterior = $_SERVER['SUPORTE_FORM_URL'] ?? null;
        $_ENV['SUPORTE_FORM_URL'] = $_SERVER['SUPORTE_FORM_URL'] = 'https://forms.exemplo.test/suporte';

        try {
            $client  = static::createClient();
            $crawler = $client->request('GET', '/login');

            $sup = $crawler->filter('a#suporte');
            self::assertSame('https://forms.exemplo.test/suporte', $sup->attr('href'));
            self::assertSame('_blank', $sup->attr('target'));
            self::assertStringContainsString('noopener', (string) $sup->attr('rel'));
        } finally {
            if ($anterior === null) {
                unset($_ENV['SUPORTE_FORM_URL'], $_SERVER['SUPORTE_FORM_URL']);
            } else {
                $_ENV['SUPORTE_FORM_URL'] = $_SERVER['SUPORTE_FORM_URL'] = $anterior;
            }
        }
    }

    #[TestDox('URL de esquema perigoso (javascript:) não vira link — o botão volta a ser inerte')]
    public function testSuporteRecusaEsquemaPerigoso(): void
    {
        $anterior = $_SERVER['SUPORTE_FORM_URL'] ?? null;
        $_ENV['SUPORTE_FORM_URL'] = $_SERVER['SUPORTE_FORM_URL'] = 'javascript:alert(1)';

        try {
            $client  = static::createClient();
            $crawler = $client->request('GET', '/login');

            self::assertSame('#', $crawler->filter('a#suporte')->attr('href'), 'O login é a única página que todo anônimo alcança.');
        } finally {
            if ($anterior === null) {
                unset($_ENV['SUPORTE_FORM_URL'], $_SERVER['SUPORTE_FORM_URL']);
            } else {
                $_ENV['SUPORTE_FORM_URL'] = $_SERVER['SUPORTE_FORM_URL'] = $anterior;
            }
        }
    }

    #[TestDox('A mensagem que chega de outra tela (flash) continua visível no login')]
    public function testFlashDeOutraTelaApareceNoLogin(): void
    {
        $client = static::createClient();

        // Caminho real: RecuperacaoSenhaController manda "Senha redefinida!" para cá.
        // A flash precisa vir na sessão QUE O CLIENTE MANDA — daí o cookie.
        $sessao = static::getContainer()->get('session.factory')->createSession();
        $sessao->getFlashBag()->add('success', 'Senha redefinida! Entre com a senha nova.');
        $sessao->save();
        $client->getCookieJar()->set(new Cookie($sessao->getName(), $sessao->getId()));

        $crawler = $client->request('GET', '/login');

        self::assertStringContainsString(
            'Senha redefinida!',
            $crawler->filter('.no-sidebar')->text(),
            'Quem redefiniu a senha precisa ler a confirmação ao chegar aqui.',
        );

        // O parágrafo acima só prova que a mensagem está no DOM. Se ela está VISÍVEL é
        // outra pergunta, e PHPUnit não a responde: o céu é `position:fixed; z-index:0`
        // e cobriria a flash, que não é posicionada. A única guarda testável é a
        // presença da regra — quem a apagar quebra aqui em vez de na tela do usuário.
        self::assertStringContainsString(
            '.no-sidebar > .alert{position:relative; z-index:2}',
            (string) $client->getResponse()->getContent(),
            'Sem esta regra o céu pinta por cima da flash. Confirmação de verdade é smoke na tela.',
        );
    }

    #[TestDox('A tela continua com um único link para a recuperação de senha')]
    public function testLinksDeApoio(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
        self::assertCount(1, $crawler->filter('a[href="/cadastro"]'));
    }
}
