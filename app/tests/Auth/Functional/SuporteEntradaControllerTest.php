<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\SuporteEntradaController;
use App\Auth\Entity\MensagemSuporte;
use App\Auth\Service\PaginaDeEntrada;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Auth\Cenario\CenarioDaEntrada;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Falar com suporte" e ferramentas SÓ MASTER da tela de entrada (spec login-desenho-10, comportamentos 5 e 6).
 *
 * O CSRF é trocado por um armazenamento fixo (token válido = 'TOKEN_<id>') e o limitador por um espião, para provar
 * QUANDO a cota é gasta: POST inválido, com CSRF errado ou do robô (honeypot) não gasta.
 */
#[CoversClass(SuporteEntradaController::class)]
final class SuporteEntradaControllerTest extends JusPrimeWebTestCase
{
    use CenarioDaEntrada;

    private KernelBrowser $client;

    private ?RateLimiterFactoryEspiao $limite = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFixo();
        $this->limparAjustesDaEntrada();
    }

    // ---------------------------------------------------------------- suporte

    #[TestDox('Anônimo envia: grava a mensagem, avisa o Master no sino e responde ok com o e-mail')]
    public function testSuporteAnonimoGravaEAvisaOMaster(): void
    {
        $master = $this->master('Marta Master');
        $email = uniqid('suporte_') . '@adv.com';

        $this->enviarSuporte(['email' => '  ' . $email . ' ', 'assunto' => 'Não consigo entrar']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'email' => $email], $this->resposta());
        $mensagem = $this->mensagemDe($email);
        self::assertNotNull($mensagem);
        self::assertSame('Ana Souza', $mensagem->getNome());
        self::assertSame('Não consigo entrar', $mensagem->getAssunto());
        self::assertSame('Não consigo entrar desde ontem.', $mensagem->getMensagem());
        $avisos = $this->avisosDoSuporte($master);
        self::assertCount(1, $avisos);
        self::assertSame('Suporte: Ana Souza · Não consigo entrar', $avisos[0]['mensagem']);
        self::assertSame('/suporte/mensagens', $avisos[0]['url']);
        self::assertCount(1, $this->limite()->chavesConsumidas, 'o POST válido gasta a cota do IP');
    }

    #[TestDox('Assunto fora da lista do desenho é gravado como nulo')]
    public function testAssuntoForaDaListaViraNulo(): void
    {
        $email = uniqid('suporte_') . '@adv.com';

        $this->enviarSuporte(['email' => $email, 'assunto' => 'Quero desconto']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->mensagemDe($email)?->getAssunto());
    }

    #[TestDox('Honeypot preenchido: responde ok igual, mas não grava, não avisa e não gasta cota')]
    public function testHoneypot(): void
    {
        $master = $this->master();
        $email = uniqid('robo_') . '@adv.com';

        $this->enviarSuporte(['email' => $email, 'site' => 'https://spam.example.com']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'email' => $email], $this->resposta());
        self::assertNull($this->mensagemDe($email));
        self::assertSame([], $this->avisosDoSuporte($master));
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    /** @return iterable<string, array{string}> */
    public static function emailsInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'sem arroba' => ['ana.adv.com'];
        yield 'sem ponto depois da arroba' => ['ana@adv'];
        yield 'com espaço' => ['ana souza@adv.com'];
    }

    #[DataProvider('emailsInvalidos')]
    #[TestDox('E-mail inválido: 422 com o texto do desenho, sem gravar nem gastar cota')]
    public function testEmailInvalido(string $email): void
    {
        $this->enviarSuporte(['email' => $email]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe um e-mail válido para a resposta.'], $this->resposta());
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    /** @return iterable<string, array{string}> */
    public static function mensagensCurtas(): iterable
    {
        yield 'vazia' => [''];
        yield 'só espaços' => ['        '];
        yield 'quatro letras com espaços em volta' => ['   abcd   '];
    }

    #[DataProvider('mensagensCurtas')]
    #[TestDox('Mensagem com menos de 5 caracteres depois de aparar: 422 com o texto do desenho')]
    public function testMensagemCurta(string $mensagem): void
    {
        $email = uniqid('suporte_') . '@adv.com';

        $this->enviarSuporte(['email' => $email, 'mensagem' => $mensagem]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Escreva a sua mensagem.'], $this->resposta());
        self::assertNull($this->mensagemDe($email));
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    #[TestDox('E-mail é conferido antes da mensagem')]
    public function testEmailAntesDaMensagem(): void
    {
        $this->enviarSuporte(['email' => 'x', 'mensagem' => '']);

        self::assertSame(['erro' => 'Informe um e-mail válido para a resposta.'], $this->resposta());
    }

    #[TestDox('CSRF inválido ou ausente: 403 "Sessão expirada", sem gravar nem gastar cota')]
    public function testCsrfInvalido(): void
    {
        $email = uniqid('suporte_') . '@adv.com';

        $this->enviarSuporte(['email' => $email, '_token' => 'TOKEN_outro']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $this->resposta());

        $this->postJson('/login/suporte', ['email' => $email, 'mensagem' => 'Mensagem sem token.']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->mensagemDe($email));
        self::assertSame([], $this->limite()->chavesConsumidas);
    }

    #[TestDox('Corpo que não é JSON cai no CSRF (403)')]
    public function testCorpoQuebrado(): void
    {
        $this->client->request('POST', '/login/suporte', [], [], ['CONTENT_TYPE' => 'application/json'], '{quebrado');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Cota do IP estourada: 429 com o texto e nada gravado')]
    public function testCotaEstourada(): void
    {
        $this->limite(aceita: false);
        $email = uniqid('suporte_') . '@adv.com';

        $this->enviarSuporte(['email' => $email]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], $this->resposta());
        self::assertNull($this->mensagemDe($email));
    }

    // ---------------------------------------------------------- destinatário

    #[TestDox('Master escolhe uma pessoa da equipe do escritório atual e recebe o texto do desenho')]
    public function testMasterEscolheDestinatarioDaEquipe(): void
    {
        $master = $this->master('Marta Master');
        $colega = $this->colega($master, 'Carla Lima Souza');
        $this->entrarComo($master);

        $this->postJson('/login/suporte/destinatario', ['userId' => $colega->getId(), '_token' => 'TOKEN_entrada_ajuste']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'texto' => 'A partir de agora, Carla recebe as mensagens no Chat I.A.'], $this->resposta());
        $this->em()->clear();
        self::assertSame($colega->getId(), static::getContainer()->get(PaginaDeEntrada::class)->destinatarioDoSuporte()?->getId());
    }

    #[TestDox('Destinatário: pessoa fora das opções dá 422 "Pessoa inválida."')]
    public function testDestinatarioForaDasOpcoes(): void
    {
        $master = $this->master();
        $estranho = $this->pessoa('Ester Estranha');
        $this->entrarComo($master);

        $this->postJson('/login/suporte/destinatario', ['userId' => $estranho->getId(), '_token' => 'TOKEN_entrada_ajuste']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Pessoa inválida.'], $this->resposta());

        $this->postJson('/login/suporte/destinatario', ['userId' => 'abc', '_token' => 'TOKEN_entrada_ajuste']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Destinatário: Master com CSRF errado recebe 403 "Sessão expirada"')]
    public function testDestinatarioCsrfInvalido(): void
    {
        $master = $this->master();
        $this->entrarComo($master);

        $this->postJson('/login/suporte/destinatario', ['userId' => $master->getId(), '_token' => 'TOKEN_suporte_entrada']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $this->resposta());
    }

    #[TestDox('Destinatário: usuário comum e anônimo recebem 403 "Só o Master." e nada é gravado')]
    public function testDestinatarioSoMaster(): void
    {
        $master = $this->master();
        $comum = $this->pessoa('Carla Comum');

        $this->postJson('/login/suporte/destinatario', ['userId' => $master->getId(), '_token' => 'TOKEN_entrada_ajuste']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só o Master.'], $this->resposta());

        $this->entrarComo($comum);
        $this->postJson('/login/suporte/destinatario', ['userId' => $comum->getId(), '_token' => 'TOKEN_entrada_ajuste']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só o Master.'], $this->resposta());

        $this->em()->clear();
        self::assertNull(static::getContainer()->get(PaginaDeEntrada::class)->destinatarioDoSuporte());
    }

    // ------------------------------------------------------------------ links

    #[TestDox('Master salva o endereço do LinkedIn: aparece na resposta e para o visitante')]
    public function testMasterSalvaLink(): void
    {
        $this->entrarComo($this->master());

        $this->link('linkedin', ['acao' => 'salvar', 'url' => ' https://www.linkedin.com/company/bluejus ']);

        self::assertResponseIsSuccessful();
        $resposta = $this->resposta();
        self::assertTrue($resposta['ok']);
        self::assertSame('https://www.linkedin.com/company/bluejus', $this->linkDaResposta($resposta, 'linkedin')['url']);
        self::assertCount(4, $resposta['links'], 'o Master vê os quatro links');
        self::assertSame('https://www.linkedin.com/company/bluejus', $this->linkDoVisitante('linkedin')['url'] ?? null);
    }

    #[TestDox('Endereço inválido: 422 com o texto do desenho e nada muda')]
    public function testLinkInvalido(): void
    {
        $this->entrarComo($this->master());

        $this->link('termos', ['acao' => 'salvar', 'url' => 'www.bluejus.com.br']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe um endereço começando com https://'], $this->resposta());
        self::assertSame(PaginaDeEntrada::LINKS['termos'][2], $this->linkDoVisitante('termos')['url'] ?? null);
    }

    #[TestDox('Restaurar volta ao endereço padrão e à visibilidade')]
    public function testRestaurarVoltaAoPadrao(): void
    {
        $this->entrarComo($this->master());
        $this->link('termos', ['acao' => 'salvar', 'url' => 'https://exemplo.com.br/termos']);
        $this->link('termos', ['acao' => 'ocultar']);

        $this->link('termos', ['acao' => 'restaurar']);

        self::assertResponseIsSuccessful();
        $termos = $this->linkDaResposta($this->resposta(), 'termos');
        self::assertSame(PaginaDeEntrada::LINKS['termos'][2], $termos['url']);
        self::assertFalse($termos['oculto']);
        self::assertNotNull($this->linkDoVisitante('termos'));
    }

    #[TestDox('Ocultar tira o link do rodapé do visitante; Mostrar devolve')]
    public function testOcultarEMostrar(): void
    {
        $this->entrarComo($this->master());

        $this->link('priv', ['acao' => 'ocultar']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->linkDaResposta($this->resposta(), 'priv')['oculto'], 'o Master continua vendo, marcado como oculto');
        self::assertNull($this->linkDoVisitante('priv'));

        $this->link('priv', ['acao' => 'mostrar']);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->linkDoVisitante('priv'));
    }

    #[TestDox('Ação desconhecida: 422')]
    public function testAcaoDesconhecida(): void
    {
        $this->entrarComo($this->master());

        $this->link('termos', ['acao' => 'apagar']);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Links: usuário comum e anônimo recebem 403 "Só o Master." e nada muda')]
    public function testLinkSoMaster(): void
    {
        $this->link('termos', ['acao' => 'ocultar']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só o Master.'], $this->resposta());

        $this->entrarComo($this->pessoa('Carla Comum'));
        $this->link('termos', ['acao' => 'ocultar']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Só o Master.'], $this->resposta());

        self::assertNotNull($this->linkDoVisitante('termos'));
    }

    #[TestDox('Links: chave fora das quatro não existe (404)')]
    public function testChaveDesconhecida(): void
    {
        $this->entrarComo($this->master());

        $this->link('facebook', ['acao' => 'ocultar']);

        self::assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------- mensagens

    #[TestDox('/suporte/mensagens: o Master vê a lista com e-mail clicável')]
    public function testMasterVeAsMensagens(): void
    {
        $email = uniqid('suporte_') . '@adv.com';
        $this->enviarSuporte(['email' => $email, 'mensagem' => "Primeira linha\nSegunda linha"]);
        $this->entrarComo($this->master());

        $crawler = $this->client->request('GET', '/suporte/mensagens');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mensagens de suporte');
        self::assertCount(1, $crawler->filter('a[href="mailto:' . $email . '"]'));
        self::assertStringContainsString('Primeira linha<br />', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('/suporte/mensagens: o destinatário escolhido também vê')]
    public function testDestinatarioVeAsMensagens(): void
    {
        $escolhida = $this->pessoa('Carla Escolhida');
        $this->escolherDestinatario($escolhida);
        $this->entrarComo($escolhida);

        $this->client->request('GET', '/suporte/mensagens');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('/suporte/mensagens: outro usuário recebe 404')]
    public function testComumNaoVeAsMensagens(): void
    {
        $this->entrarComo($this->pessoa('Carla Comum'));

        $this->client->request('GET', '/suporte/mensagens');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('/suporte/mensagens: anônimo vai para o login')]
    public function testAnonimoVaiParaOLogin(): void
    {
        $this->client->request('GET', '/suporte/mensagens');

        self::assertResponseRedirects('/login');
    }

    // ---------------------------------------------------------------- apoio

    private function instalarCsrfFixo(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    /**
     * Troca o limitador pelo espião antes da primeira requisição ao controller (que o recebe no construtor):
     * depois de criado, o container de teste não deixa mais substituir.
     */
    private function limite(bool $aceita = true): RateLimiterFactoryEspiao
    {
        if ($this->limite === null) {
            $this->limite = new RateLimiterFactoryEspiao($aceita);
            static::getContainer()->set('limiter.suporte_entrada', $this->limite);
        }

        return $this->limite;
    }

    /** Entra com escritório na sessão e Termos aceitos (passa pelos portões de escritório e de Termos). */
    private function entrarComo(User $user): void
    {
        $tenant = $this->em()->find(Tenant::class, $this->escritorioDe($user));
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param array<string, mixed> $campos */
    private function enviarSuporte(array $campos): void
    {
        $this->limite();
        $this->postJson('/login/suporte', $campos + [
            'nome' => 'Ana Souza',
            'email' => 'ana@adv.com',
            'assunto' => '',
            'mensagem' => 'Não consigo entrar desde ontem.',
            'site' => '',
            '_token' => 'TOKEN_suporte_entrada',
        ]);
    }

    /** @param array<string, mixed> $campos */
    private function link(string $chave, array $campos): void
    {
        $this->postJson('/login/links/' . $chave, $campos + ['_token' => 'TOKEN_entrada_ajuste']);
    }

    /** @param array<string, mixed> $corpo */
    private function postJson(string $url, array $corpo): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function mensagemDe(string $email): ?MensagemSuporte
    {
        $this->em()->clear();

        return $this->em()->getRepository(MensagemSuporte::class)->findOneBy(['email' => $email]);
    }

    /**
     * @param array<string, mixed> $resposta
     *
     * @return array<string, mixed>
     */
    private function linkDaResposta(array $resposta, string $chave): array
    {
        foreach ($resposta['links'] as $link) {
            if ($link['chave'] === $chave) {
                return $link;
            }
        }
        self::fail('Link ' . $chave . ' fora da resposta.');
    }

    /**
     * O rodapé de quem não está logado, pelo PaginaDeEntrada (a tela é de outro bloco).
     *
     * @return array<string, mixed>|null
     */
    private function linkDoVisitante(string $chave): ?array
    {
        $this->em()->clear();
        foreach (static::getContainer()->get(PaginaDeEntrada::class)->montar(null)->links as $link) {
            if ($link['chave'] === $chave) {
                return $link;
            }
        }

        return null;
    }
}
