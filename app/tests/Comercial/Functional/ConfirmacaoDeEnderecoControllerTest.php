<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\ConfirmacaoDeEnderecoController;
use App\Comercial\Entity\FatoDeConsentimento;
use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A rota pública do link de confirmação de endereço (spec `comercial-e-emails.md` §3.3, I9): sem login; o GET só
 * mostra o botão "Confirmar" (leitor de link não confirma ninguém); o POST, com CSRF do token, grava com o IP e o
 * agente; vencido, usado ou desconhecido dão a mesma página; nenhuma página mostra o endereço.
 *
 * O pedido é semeado direto pelos repositórios (o envio é do Carteiro, que não entra aqui).
 */
#[CoversClass(ConfirmacaoDeEnderecoController::class)]
final class ConfirmacaoDeEnderecoControllerTest extends JusPrimeWebTestCase
{
    private const LISTA = 'vagas_alerta';
    private const EMAIL = 'joana@gmail.com';
    private const AGENTE = 'NavegadorDeTeste/1.0';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_USER_AGENT' => self::AGENTE, 'REMOTE_ADDR' => '198.51.100.7']);
        $this->client->disableReboot();
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** Semeia a pendência como o `pedir` faria e devolve o token do link. */
    private function pendencia(?Tenant $tenant = null, string $expira = '+7 days'): string
    {
        $token = bin2hex(random_bytes(32));
        $agora = new \DateTimeImmutable();
        $tenantId = $tenant === null ? null : (int) $tenant->getId();
        static::getContainer()->get(FatoDeConsentimentoRepository::class)->registrar(
            $tenantId,
            FatoDeConsentimento::PEDIDO,
            self::LISTA,
            self::EMAIL,
            ['origem' => 'pagina-publica-vagas', 'versao' => 'v1', 'texto' => 'Quero receber os alertas de vagas.'],
            null,
            null,
            $agora,
        );
        static::getContainer()->get(ConfirmacaoDeEnderecoRepository::class)
            ->gravarPedido($tenantId, self::LISTA, self::EMAIL, hash('sha256', $token), $agora, $agora->modify($expira));

        return $token;
    }

    /** @return list<array<string, mixed>> */
    private function confirmados(): array
    {
        return $this->conexao()->fetchAllAssociative(
            "SELECT tenant_id, ip, agente, versao_consentimento FROM comercial_consentimento WHERE email = ? AND fato = 'confirmado'",
            [self::EMAIL],
        );
    }

    private function confirmadoEm(): mixed
    {
        return $this->conexao()->fetchOne('SELECT confirmado_em FROM comercial_confirmacao_endereco WHERE email = ?', [self::EMAIL]);
    }

    private function semEndereco(): void
    {
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::EMAIL, $html);
        self::assertStringNotContainsString('joana', $html);
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('–', $html);
    }

    private function clicarConfirmar(string $token): void
    {
        $crawler = $this->client->request('GET', '/e/confirmar/' . $token);
        $this->client->submit($crawler->selectButton('Confirmar')->form());
    }

    #[TestDox('GET sem login só mostra o botão "Confirmar" (POST na mesma URL, com CSRF) e não grava nada, nem repetido')]
    public function testGetNaoGrava(): void
    {
        $token = $this->pendencia();

        $this->client->request('GET', '/e/confirmar/' . $token);
        $crawler = $this->client->request('GET', '/e/confirmar/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Confirme seu e-mail');
        $form = $crawler->filter('main form[method="post"]');
        self::assertSame('/e/confirmar/' . $token, $form->attr('action'));
        self::assertSame('Confirmar', trim($form->filter('button')->text()));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $this->semEndereco();
        self::assertNull($this->confirmadoEm(), 'o GET não confirma');
        self::assertSame([], $this->confirmados());
    }

    #[TestDox('POST do botão confirma com o IP e o agente, diz o que a pessoa vai receber e oferece o descadastro da mesma lista')]
    public function testPostConfirma(): void
    {
        $token = $this->pendencia();

        $this->clicarConfirmar($token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Endereço confirmado. Você vai receber os alertas de vagas do BlueJus.');
        $link = (string) $this->client->getCrawler()->filter('main a.descadastro')->attr('href');
        self::assertStringContainsString('/email/descadastro?', $link);
        self::assertSame('Não quero mais receber', $this->client->getCrawler()->filter('main a.descadastro')->text());
        $this->semEndereco();
        self::assertNotNull($this->confirmadoEm());
        self::assertSame([['tenant_id' => null, 'ip' => '198.51.100.7', 'agente' => self::AGENTE, 'versao_consentimento' => 'v1']], $this->confirmados());
    }

    #[TestDox('O link de descadastro da página é o gerarLink da 0d para o mesmo escopo, endereço e lista')]
    public function testLinkDeDescadastro(): void
    {
        $token = $this->pendencia();
        $esperado = static::getContainer()->get(DescadastroDeEmailInterface::class)->gerarLink(null, self::EMAIL, self::LISTA);

        $this->clicarConfirmar($token);

        $obtido = (string) $this->client->getCrawler()->filter('main a.descadastro')->attr('href');
        self::assertSame(self::caminhoComConsulta($esperado), self::caminhoComConsulta($obtido), 'o host pode variar (request x DEFAULT_URI); a assinatura cobre caminho e consulta');
    }

    private static function caminhoComConsulta(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    #[TestDox('O mesmo link usado de novo: "Este link venceu ou já foi usado", sem segundo fato')]
    public function testUsado(): void
    {
        $token = $this->pendencia();
        $this->clicarConfirmar($token);

        $this->clicarConfirmar($token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Este link venceu ou já foi usado. Peça a confirmação de novo.');
        $this->semEndereco();
        self::assertCount(1, $this->confirmados());
    }

    #[TestDox('Link vencido e token desconhecido dão a mesma página e não gravam')]
    public function testVencidoEDesconhecido(): void
    {
        $vencido = $this->pendencia(expira: '-1 minute');

        foreach ([$vencido, str_repeat('c', 64)] as $token) {
            $this->clicarConfirmar($token);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Este link venceu ou já foi usado. Peça a confirmação de novo.');
            $this->semEndereco();
        }
        self::assertNull($this->confirmadoEm());
        self::assertSame([], $this->confirmados());
    }

    #[TestDox('POST sem CSRF válido não grava e devolve a página com o botão')]
    public function testSemCsrf(): void
    {
        $token = $this->pendencia();

        $this->client->request('POST', '/e/confirmar/' . $token, ['_token' => 'falso', 'confirmar' => '1']);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('main form button');
        self::assertNull($this->confirmadoEm());
        self::assertSame([], $this->confirmados());
    }

    #[TestDox('O CSRF de um link não serve para outro')]
    public function testCsrfDeOutroToken(): void
    {
        $um = $this->pendencia();
        $crawler = $this->client->request('GET', '/e/confirmar/' . str_repeat('d', 64));
        $csrfDeOutro = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/e/confirmar/' . $um, ['_token' => $csrfDeOutro]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->confirmados());
    }

    #[TestDox('Confirmar depois de descadastrado reativa só aquela lista no descadastro (nova_confirmacao)')]
    public function testReativa(): void
    {
        $descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $descadastro->descadastrar(null, self::EMAIL, self::LISTA, 'link_do_email');
        $descadastro->descadastrar(null, self::EMAIL, 'outro_assunto', 'link_do_email');
        $token = $this->pendencia();

        $this->clicarConfirmar($token);

        self::assertFalse($descadastro->estaDescadastrado(null, self::EMAIL, self::LISTA));
        self::assertTrue($descadastro->estaDescadastrado(null, self::EMAIL, 'outro_assunto'));
        $origem = $this->conexao()->fetchOne(
            'SELECT reativado_origem FROM email_descadastro WHERE tenant_id IS NULL AND email = ? AND assunto = ?',
            [self::EMAIL, self::LISTA],
        );
        self::assertSame('nova_confirmacao', $origem);
    }

    #[TestDox('Pendência de escritório: confirma no escopo dele (fato com o tenant_id), sem tocar a plataforma')]
    public function testEscritorio(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Silva Advogados');
        $em->persist($tenant);
        $em->flush();
        $token = $this->pendencia($tenant);

        $this->clicarConfirmar($token);

        self::assertSelectorTextContains('main', 'Endereço confirmado.');
        self::assertSame([(int) $tenant->getId()], array_map(static fn (array $f): int => (int) $f['tenant_id'], $this->confirmados()));
        $plataforma = $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_confirmacao_endereco WHERE tenant_id IS NULL AND email = ?', [self::EMAIL]);
        self::assertSame(0, (int) $plataforma);
    }
}
