<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Controller\BoletoDeApoioController;
use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\MessageHandler\ReconsultarCobrancaNoBancoHandler;
use App\Contribua\Twig\BoletoRuntime;
use App\Contribua\UseCase\GerarBoletoDeApoioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Boleto de apoio (chave `bol`, só com a Cora) de ponta a ponta contra uma Cora falsa: botão na seção Apoie,
 * emissão, idempotência, recusas e a confirmação pela conciliação e pelo aviso. Spec: docs/specs/contribua-boleto.md.
 */
#[CoversClass(BoletoDeApoioController::class)]
#[CoversClass(GerarBoletoDeApoioUseCase::class)]
#[CoversClass(BoletoRuntime::class)]
final class BoletoDeApoioTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';
    private const CNPJ = '11222333000181';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CoraFalsa $cora;
    private Tenant $escritorio;
    private User $primario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->cora = new CoraFalsa();
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        static::getContainer()->set(CofreDeCertificado::class, $cofre);
        static::getContainer()->set(ClienteCora::class, new ClienteCora($this->cora->cliente(), new ArrayAdapter(), $cofre));
        $this->instalarCsrf();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A', self::CNPJ);
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Gera o boleto: 200 com a linha digitável, o PDF, o vencimento e o Pix; pedir de novo devolve o mesmo, sem outra fatura na Cora')]
    public function testGera(): void
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $r = $this->boleto(2000, '11.222.333/0001-81');
        self::assertResponseIsSuccessful();
        $deNovo = $this->boleto(2000, self::CNPJ);

        self::assertTrue($r['ok']);
        self::assertSame('40390000007550000000000000000000189170000005000', $r['boleto']['linhaDigitavel']);
        self::assertStringStartsWith('https://web.stage.cora.com.br/boleto/inv_', $r['boleto']['url']);
        self::assertSame((new \DateTimeImmutable('today +3 days'))->format('d/m/Y'), $r['boleto']['vencimento']);
        self::assertStringStartsWith('000201', $r['boleto']['pixCopiaECola']);
        self::assertSame($r, $deNovo);
        self::assertCount(1, $this->criacoes(), 'pedir de novo não cria outra fatura');
        $corpo = json_decode((string) $this->criacoes()[0]['opcoes']['body'], true);
        self::assertSame(['BANK_SLIP', 'PIX'], $corpo['payment_forms']);
        self::assertSame($this->primario->getEmail(), $corpo['customer']['email']);
        self::assertSame(['identity' => self::CNPJ, 'type' => 'CNPJ'], $corpo['customer']['document']);
        $linha = $this->banco()->fetchAssociative('SELECT * FROM contribua_cobranca_pix WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]);
        self::assertSame(['boleto', 'aberta', 2000], [$linha['forma'], $linha['situacao'], (int) $linha['valor_centavos']]);
        self::assertStringNotContainsString(self::CNPJ, (string) json_encode($linha), 'o documento não é gravado');
    }

    #[TestDox('O boleto não atrapalha o PIX dinâmico do mesmo valor: cada forma tem a sua cobrança')]
    public function testPixSegueSeparado(): void
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->boleto(2000, self::CNPJ);

        $this->client->request('GET', '/contribua/pix?valor=20');

        self::assertStringContainsString('qrcode-h.cora.com.br', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['codigo']);
        self::assertCount(2, $this->criacoes());
        self::assertSame(['PIX'], json_decode((string) $this->criacoes()[1]['opcoes']['body'], true)['payment_forms']);
    }

    #[TestDox('Chave Boleto desligada: 404 e nenhum botão na seção Apoie')]
    public function testChaveDesligada(): void
    {
        $this->conectar('producao', false);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->boleto(2000, self::CNPJ);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->pagina()->filter('[data-cb-boleto]'));
        $boletos = array_filter($this->criacoes(), static fn (array $p): bool => str_contains((string) $p['opcoes']['body'], 'BANK_SLIP'));
        self::assertSame([], $boletos, 'a página cria o PIX do valor padrão, nunca um boleto');
    }

    #[TestDox('Outro banco (Inter): o boleto é recusado e o botão some; na Integração bancária a chave Boleto fica desabilitada com a nota')]
    public function testOutroBanco(): void
    {
        $this->conectar();
        $this->banco()->executeStatement("UPDATE contribua_integracao_bancaria SET banco = 'inter'");
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->boleto(2000, self::CNPJ);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->pagina()->filter('[data-cb-boleto]'));

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $chave = $this->pagina()->filter('.cc-recurso[data-cc-recurso="bol"]');
        self::assertNotNull($chave->attr('disabled'));
        self::assertSame('Disponível só com a Cora', trim($chave->filter('[data-cc-so-cora-nota]')->text()));
        self::assertNull($chave->filter('[data-cc-so-cora-nota]')->attr('hidden'));
    }

    #[TestDox('Com a Cora, a chave Boleto da Integração bancária fica habilitada e a nota escondida')]
    public function testCoraHabilita(): void
    {
        $this->conectar();
        $chave = $this->pagina()->filter('.cc-recurso[data-cc-recurso="bol"]');

        self::assertNull($chave->attr('disabled'));
        self::assertNotNull($chave->filter('[data-cc-so-cora-nota]')->attr('hidden'));
    }

    #[TestDox('Valor abaixo de R$ 5,00 e documento inválido: 422 com a mensagem; nada vai para a Cora')]
    public function testRecusas(): void
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $baixo = $this->boleto(499, self::CNPJ);
        self::assertResponseStatusCodeSame(422);
        $invalido = $this->boleto(2000, '11222333000180');
        self::assertResponseStatusCodeSame(422);

        self::assertSame('O boleto tem valor mínimo de R$ 5,00.', $baixo['erro']);
        self::assertSame('Informe um CPF ou CNPJ válido do pagador.', $invalido['erro']);
        self::assertSame([], $this->criacoes());
    }

    #[TestDox('Cora fora do ar: 503 com a mensagem honesta, sem o corpo do erro e sem linha gravada')]
    public function testCoraFora(): void
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->cora->statusDaCriacao = 500;

        $r = $this->boleto(2000, self::CNPJ);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['ok' => false, 'erro' => 'Não foi possível gerar o boleto agora. Tente de novo em instantes.'], $r);
        self::assertSame(0, (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM contribua_cobranca_pix WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
    }

    #[TestDox('Sem CSRF: 403; ambiente de testes: só o escritório do Master gera')]
    public function testCsrfESandbox(): void
    {
        $this->conectar('sandbox');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/boleto', ['centavos' => 2000, 'documento' => self::CNPJ]);
        self::assertResponseStatusCodeSame(403);
        $this->boleto(2000, self::CNPJ);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertTrue($this->boleto(2000, self::CNPJ)['ok']);
    }

    #[TestDox('Tela: o botão "Gerar boleto" fica na coluna do QR, abaixo dos valores, com o documento do escritório pré-preenchido')]
    public function testTela(): void
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $pagina = $this->pagina();

        $botao = $pagina->filter('#apoie .ct-apoie > .ct-apoie-qr > .cb-boleto > [data-cb-abrir]');
        self::assertCount(1, $botao);
        self::assertSame('Gerar boleto', trim($botao->text()));
        self::assertCount(1, $botao->filter('i.bi-upc'));
        self::assertCount(1, $pagina->filter('.ct-apoie-qr > .ct-valores ~ .cb-boleto'), 'abaixo dos valores');
        self::assertSame(self::CNPJ, $pagina->filter('.cb-boleto > .cb-pedido > .cb-campo > input[data-cb-documento]')->attr('value'));
        $pdf = $pagina->filter('.cb-resultado > .cb-botoes > a[data-cb-pdf]');
        self::assertSame(['_blank', 'noopener noreferrer'], [$pdf->attr('target'), $pdf->attr('rel')]);
    }

    #[TestDox('Boleto pago: a conciliação confirma o apoio, com o código do boleto')]
    public function testConciliacaoConfirma(): void
    {
        $idCora = $this->boletoPago();
        $comando = new CommandTester((new Application(static::$kernel))->find('app:contribua:conciliar'));

        $comando->execute([]);

        self::assertStringContainsString('1 apoio(s) confirmado(s)', $comando->getDisplay());
        $apoio = $this->banco()->fetchAssociative('SELECT origem, id_cora, txid FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]);
        self::assertSame(['cora', $idCora], [$apoio['origem'], $apoio['id_cora']]);
        self::assertMatchesRegularExpression('/^BJ\d{6}-B[0-9a-f]{10}$/', $apoio['txid']);
        self::assertSame('paga', $this->banco()->fetchOne('SELECT situacao FROM contribua_cobranca_pix WHERE id_no_banco = :i', ['i' => $idCora]));
    }

    #[TestDox('Boleto pago: o aviso da Cora enfileira a reconsulta, que confirma o apoio uma vez')]
    public function testAvisoConfirma(): void
    {
        $idCora = $this->boletoPago();
        $this->client->request('POST', '/api/webhooks/cora/pix', [], [], ['HTTP_WEBHOOK_EVENT_ID' => 'evt_b1', 'HTTP_WEBHOOK_EVENT_TYPE' => 'invoice.paid', 'HTTP_WEBHOOK_RESOURCE_ID' => $idCora]);
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');
        $mensagens = array_map(static fn ($e) => $e->getMessage(), $fila->getSent());
        self::assertCount(1, $mensagens);
        self::assertInstanceOf(ReconsultarCobrancaNoBanco::class, $mensagens[0]);

        $handler = static::getContainer()->get(ReconsultarCobrancaNoBancoHandler::class);
        $handler($mensagens[0]);
        $handler($mensagens[0]);

        self::assertSame(1, (int) $this->banco()->fetchOne('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t AND id_cora = :i', ['t' => $this->escritorio->getId(), 'i' => $idCora]));
    }

    private function boletoPago(): string
    {
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertTrue($this->boleto(2000, self::CNPJ)['ok']);
        $idCora = (string) array_key_first($this->cora->status);
        $this->cora->status[$idCora] = 'PAID';

        return $idCora;
    }

    /** @return list<array{metodo: string, url: string, opcoes: array<string, mixed>}> */
    private function criacoes(): array
    {
        return array_values(array_filter($this->cora->pedidos, static fn (array $p): bool => 'POST' === $p['metodo'] && str_ends_with($p['url'], '/v2/invoices/')));
    }

    private function conectar(string $ambiente = 'producao', bool $boleto = true): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/cert.pem', $par['certificado']);
        file_put_contents($dir . '/chave.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [
            new UploadedFile($dir . '/cert.pem', 'cert.pem', null, null, true),
            new UploadedFile($dir . '/chave.key', 'chave.key', null, null, true),
        ]]);
        self::assertTrue($this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => $ambiente, 'clientId' => 'cliente-1', 'recursos' => ['cob' => '1', 'conc' => '1', 'bol' => $boleto ? '1' : '0', 'rel' => '1']])['ok']);
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
    }

    /** @return array<string, mixed> */
    private function boleto(int $centavos, string $documento): array
    {
        return $this->post('/contribua/boleto', ['centavos' => $centavos, 'documento' => $documento]);
    }

    private function pagina(): Crawler
    {
        $this->em->clear();
        $crawler = $this->client->request('GET', '/contribua');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $campos = []): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function tenant(string $nome, ?string $cnpj): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório BOLETO ' . $nome . ' ' . uniqid());
        $tenant->setCnpj($cnpj);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $papel = (new TenantRole())->setTenant($tenant)->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid())->setIsSystem($primario);
        $this->em->persist($papel);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrf(): void
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
}
