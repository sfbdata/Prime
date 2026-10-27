<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Controller\IntegracaoBancariaController;
use App\Contribua\Controller\WebhookDoBancoController;
use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\MessageHandler\ReconsultarCobrancaNoBancoHandler;
use App\Contribua\Service\CodigoPixDaContaDinamico;
use App\Contribua\UseCase\ConfirmarCobrancaPeloBancoUseCase;
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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C4 de ponta a ponta contra uma Cora falsa (respostas da documentação): o Master configura e testa; a
 * conta recebe o PIX dinâmico; o aviso da Cora (sem corpo, sem assinatura) só enfileira; a reconsulta
 * confirma o apoio uma vez; o prêmio aparece uma vez. Spec: docs/specs/contribua-c4-cora.md.
 */
#[CoversClass(IntegracaoBancariaController::class)]
#[CoversClass(WebhookDoBancoController::class)]
#[CoversClass(CodigoPixDaContaDinamico::class)]
#[CoversClass(ConfirmarCobrancaPeloBancoUseCase::class)]
#[CoversClass(ReconsultarCobrancaNoBancoHandler::class)]
final class ApoioPorPixComCoraTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

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
        static::getContainer()->get('cache.app')->deleteItem('contribua_banco_em_pausa');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A', '12345678000199');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Só o Master configura; o certificado vai cifrado (o conteúdo nunca volta); testar conecta por mTLS')]
    public function testMasterConfigura(): void
    {
        $secundario = $this->pessoa('secundario', $this->escritorio, false);
        foreach ([$this->primario, $secundario] as $quem) {
            $this->logarComTenant($this->client, $quem, $this->escritorio);
            foreach (['salvar', 'certificado', 'testar'] as $acao) {
                $this->client->request('POST', '/contribua/integracao-bancaria/' . $acao, ['_token' => self::TOKEN, 'clientId' => 'x']);
                self::assertResponseStatusCodeSame(403, $acao);
            }
        }

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertSame('Informe o Client ID e envie o certificado antes de testar.', $this->post('/contribua/integracao-bancaria/testar')['mensagem']);
        self::assertSame('Informe o Client ID fornecido pelo banco.', $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'sandbox', 'clientId' => ' '])['erro']);
        $this->conectar();

        $linha = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT estado, arquivos, certificado_cifrado, chave_cifrada FROM contribua_integracao_bancaria');
        self::assertSame('ok', $linha['estado']);
        self::assertSame(['cert.pem', 'chave.key'], json_decode($linha['arquivos'], true));
        self::assertStringNotContainsString('BEGIN', (string) base64_decode((string) $linha['certificado_cifrado']));
        self::assertStringNotContainsString('PRIVATE', (string) base64_decode((string) $linha['chave_cifrada']));
        $html = $this->pagina();
        self::assertStringContainsString('Integração bancária (API)', $html);
        self::assertStringContainsString('cert.pem, chave.key', $html);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $html);
    }

    #[TestDox('PIX da conta: dinâmico da Cora com a integração; reaproveita a cobrança do mesmo valor; abaixo de R$ 5 e sem CNPJ cai no estático')]
    public function testPixDinamico(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $vinte = $this->pix(20);
        $deNovo = $this->pix(20);
        $tres = $this->pix(3);

        self::assertStringContainsString('qrcode-h.cora.com.br', $vinte['codigo']);
        self::assertTrue($vinte['valido']);
        self::assertSame($vinte, $deNovo);
        self::assertCount(1, array_filter($this->cora->pedidos, static fn (array $p): bool => 'POST' === $p['metodo'] && str_ends_with($p['url'], '/v2/invoices/')), 'trocar para o mesmo valor não cria outra cobrança');
        self::assertStringNotContainsString('cora.com.br', $tres['codigo'], 'abaixo do mínimo da Cora: estático');

        $semCnpj = $this->tenant('B', null);
        $alguem = $this->pessoa('semcnpj', $semCnpj, true);
        $this->logarComTenant($this->client, $alguem, $semCnpj);
        self::assertStringNotContainsString('cora.com.br', $this->pix(20)['codigo'], 'sem CNPJ: estático');
    }

    #[TestDox('Aviso da Cora só enfileira; a reconsulta confirma o apoio uma vez, avisa o Master; aviso repetido e recurso alheio não fazem nada')]
    public function testWebhookConfirma(): void
    {
        $idCora = $this->cobrancaPaga();
        // O transporte em memória é zerado a cada requisição (reset dos serviços): conferir depois de cada uma.
        $this->webhook('evt_1', 'invoice.paid', $idCora);
        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        $primeira = $this->enfileiradas();
        $this->webhook('evt_1', 'invoice.paid', $idCora);
        self::assertSame([], $this->enfileiradas(), 'aviso repetido não enfileira');
        $this->webhook('evt_2', 'invoice.paid', 'inv_de_outro_sistema');
        $alheia = $this->enfileiradas();
        $this->client->request('POST', '/api/webhooks/cora/pix');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->enfileiradas(), 'sem cabeçalho não enfileira');

        self::assertCount(1, $primeira);
        self::assertSame([], $alheia, 'recurso que não é cobrança nossa não gera trabalho');
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM contribua_notificacao_recebida WHERE origem = 'cora'"), 'nem registro');
        $handler = static::getContainer()->get(ReconsultarCobrancaNoBancoHandler::class);
        foreach ([$primeira[0], $primeira[0]] as $m) {
            self::assertInstanceOf(ReconsultarCobrancaNoBanco::class, $m);
            $handler($m);
        }

        $banco = static::getContainer()->get(Connection::class);
        $apoios = $banco->fetchAllAssociative('SELECT origem, id_cora, txid, valor_centavos, confirmado_em FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]);
        self::assertCount(1, $apoios);
        self::assertSame('cora', $apoios[0]['origem']);
        self::assertSame($idCora, $apoios[0]['id_cora']);
        self::assertStringStartsWith('BJ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT) . '-', $apoios[0]['txid']);
        self::assertSame(2000, (int) $apoios[0]['valor_centavos']);
        self::assertNotNull($apoios[0]['confirmado_em']);
        self::assertSame(1, (int) $banco->fetchOne("SELECT COUNT(*) FROM notificacao WHERE tipo = 'contribua.apoio_confirmado' AND usuario_id = :u", ['u' => $this->master->getId()]));
        self::assertNotNull($banco->fetchOne("SELECT processada_em FROM contribua_notificacao_recebida WHERE origem = 'cora' AND id_evento = 'evt_1'"));
    }

    #[TestDox('Prêmio do apoiador: aparece uma vez na página da conta, com o valor; visto, não volta; outra conta não o apaga')]
    public function testPremio(): void
    {
        $idCora = $this->cobrancaPaga();
        static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('cora', $idCora);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $html = $this->pagina();
        self::assertStringContainsString('data-abrir="1"', $html);
        self::assertStringContainsString('data-valor="R$ 20,00"', $html);
        self::assertStringContainsString('data-nome="Pessoa"', $html);
        preg_match('/data-apoio="(\d+)"/', $html, $m);
        preg_match('/data-url-visto="([^"]+)"/', $html, $url);
        $urlDoJs = str_replace('/0/visto', '/' . $m[1] . '/visto', html_entity_decode($url[1]));
        self::assertSame('/contribua/premio/' . $m[1] . '/visto', $urlDoJs, 'o JS troca o id na URL do parcial');

        $outro = $this->tenant('C', null);
        $deFora = $this->pessoa('defora', $outro, true);
        $this->logarComTenant($this->client, $deFora, $outro);
        $this->post('/contribua/premio/' . $m[1] . '/visto');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertStringContainsString('data-abrir="1"', $this->pagina(), 'outra conta não apaga o prêmio');

        self::assertTrue($this->post('/contribua/premio/' . $m[1] . '/visto')['ok']);
        self::assertStringContainsString('data-abrir="0"', $this->pagina());
    }

    #[TestDox('Ambiente de testes: só o escritório do Master recebe o PIX dinâmico; o pagamento de teste não vira apoio, só avisa o Master')]
    public function testSandbox(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar('sandbox');
        self::assertStringContainsString('qrcode-h.cora.com.br', $this->pix(20)['codigo'], 'o Master testa');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertStringNotContainsString('cora.com.br', $this->pix(50)['codigo'], 'as contas seguem no estático');

        $idCora = (string) array_key_first($this->cora->status);
        $this->cora->status[$idCora] = 'PAID';
        static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('cora', $idCora);

        $banco = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
        self::assertSame('paga', $banco->fetchOne('SELECT situacao FROM contribua_cobranca_pix WHERE id_no_banco = :i', ['i' => $idCora]));
        self::assertStringContainsString('Nenhum apoio foi registrado', (string) $banco->fetchOne('SELECT titulo FROM notificacao WHERE usuario_id = :u ORDER BY id DESC LIMIT 1', ['u' => $this->master->getId()]));
    }

    #[TestDox('Um pagamento, um apoio: quem clicou em "Já contribuí" tem esse apoio confirmado pelo banco, sem um segundo')]
    public function testInformadoEConfirmado(): void
    {
        $idCora = $this->cobrancaPaga();
        $this->post('/contribua/apoio', ['centavos' => 2000]);

        static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('cora', $idCora);

        $apoios = static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT origem, id_cora, confirmado_em FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]);
        self::assertCount(1, $apoios);
        self::assertSame('cora', $apoios[0]['origem']);
        self::assertSame($idCora, $apoios[0]['id_cora']);
        self::assertNotNull($apoios[0]['confirmado_em']);
    }

    #[TestDox('Cancelada no banco: a cobrança fica cancelada e nenhum apoio nasce')]
    public function testCancelada(): void
    {
        $idCora = $this->cobrancaPaga();
        $this->cora->status[$idCora] = 'CANCELLED';

        self::assertFalse(static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('cora', $idCora));
        self::assertSame('cancelada', static::getContainer()->get(Connection::class)->fetchOne('SELECT situacao FROM contribua_cobranca_pix WHERE id_no_banco = :i', ['i' => $idCora]));
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
    }

    #[TestDox('Proteções da página: teto de valor, no máximo 10 cobranças novas por conta no dia (a já criada continua valendo)')]
    public function testTetoELimite(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        // O controller limita o valor pedido ao teto do apoio (R$ 1.000.000): acima disso vale o teto, e o teto ainda é cobrável.
        self::assertStringContainsString('cora.com.br', $this->pix(1000001)['codigo'], 'acima do teto do apoio: vale o teto');
        // A do teto foi a 1ª do dia; mais 9 completam as 10 permitidas.
        for ($reais = 5; $reais < 14; ++$reais) {
            self::assertStringContainsString('cora.com.br', $this->pix($reais)['codigo']);
        }
        self::assertStringNotContainsString('cora.com.br', $this->pix(15)['codigo'], 'a 11ª cobrança do dia não é criada');
        self::assertStringContainsString('cora.com.br', $this->pix(5)['codigo'], 'a já criada continua valendo');
    }

    #[TestDox('Banco fora do ar: pausa para todos, a cobrança é refeita depois com a mesma chave; testar a conexão tira a pausa. Recusa de um pedido não pausa os outros')]
    public function testPausaERecusa(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->cora->statusDaCriacao = 500;
        self::assertStringNotContainsString('cora.com.br', $this->pix(20)['codigo']);
        $chave = $this->cora->cabecalho(end($this->cora->pedidos)['opcoes'], 'idempotency-key');
        $antes = count($this->cora->pedidos);
        self::assertStringNotContainsString('cora.com.br', $this->pix(50)['codigo'], 'em pausa');
        self::assertCount($antes, $this->cora->pedidos, 'em pausa, nem chama o banco');

        $this->cora->statusDaCriacao = null;
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertStringContainsString('cora.com.br', $this->pix(20)['codigo']);
        self::assertSame($chave, $this->cora->cabecalho(end($this->cora->pedidos)['opcoes'], 'idempotency-key'), 'refeita com a mesma chave');

        $this->cora->statusDaCriacao = 400;
        self::assertStringNotContainsString('cora.com.br', $this->pix(30)['codigo']);
        $this->cora->statusDaCriacao = null;
        self::assertStringContainsString('cora.com.br', $this->pix(40)['codigo'], 'recusa de um pedido não pausa');
        self::assertSame('cancelada', static::getContainer()->get(Connection::class)->fetchOne("SELECT situacao FROM contribua_cobranca_pix WHERE valor_centavos = 3000 AND tenant_id = :t", ['t' => $this->escritorio->getId()]));
    }

    #[TestDox('"Já contribuí" de antes da cobrança é outro pagamento: o banco cria um apoio novo, sem apagar o informado')]
    public function testInformadoAnterior(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->post('/contribua/apoio', ['centavos' => 10000]);
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE contribua_apoio SET criado_em = NOW() - INTERVAL '1 day' WHERE tenant_id = :t", ['t' => $this->escritorio->getId()]);
        $idCora = $this->cobrancaPaga();

        static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('cora', $idCora);

        $apoios = static::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT origem, valor_centavos FROM contribua_apoio WHERE tenant_id = :t ORDER BY id', ['t' => $this->escritorio->getId()]);
        self::assertSame([['origem' => 'informado', 'valor_centavos' => 10000], ['origem' => 'cora', 'valor_centavos' => 2000]], $apoios);
    }

    #[TestDox('Conciliação: confirma a cobrança paga cujo aviso se perdeu; sem integração, não faz nada')]
    public function testConciliar(): void
    {
        $comando = new CommandTester((new Application(static::$kernel))->find('app:cora:conciliar'));
        $comando->execute([]);
        self::assertStringContainsString('Nada a fazer', $comando->getDisplay());

        $this->cobrancaPaga();
        $comando->execute([]);

        self::assertStringContainsString('1 apoio(s) confirmado(s)', $comando->getDisplay());
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
    }

    /** @return list<object> */
    private function enfileiradas(): array
    {
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($e) => $e->getMessage(), $fila->getSent()));
    }

    /** Master configura e conecta; a conta gera a cobrança de R$ 20 e a Cora a dá por paga. */
    private function cobrancaPaga(): string
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar();
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->pix(20);
        $idCora = (string) array_key_first($this->cora->status);
        $this->cora->status[$idCora] = 'PAID';

        return $idCora;
    }

    private function conectar(string $ambiente = 'producao'): void
    {
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/cert.pem', $par['certificado']);
        file_put_contents($dir . '/chave.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [
            new UploadedFile($dir . '/cert.pem', 'cert.pem', null, null, true),
            new UploadedFile($dir . '/chave.key', 'chave.key', null, null, true),
        ]]);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok'], (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => $ambiente, 'clientId' => 'cliente-1', 'recursos' => ['cob' => '1', 'conc' => '1', 'bol' => '0', 'rel' => '1']])['ok']);
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
    }

    /** @return array{codigo: string, valido: bool} */
    private function pix(int $reais): array
    {
        $this->client->request('GET', '/contribua/pix?valor=' . $reais);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function webhook(string $evento, string $tipo, string $recurso): void
    {
        $this->client->request('POST', '/api/webhooks/cora/pix', [], [], ['HTTP_WEBHOOK_EVENT_ID' => $evento, 'HTTP_WEBHOOK_EVENT_TYPE' => $tipo, 'HTTP_WEBHOOK_RESOURCE_ID' => $recurso, 'HTTP_USER_AGENT' => 'Cora-Webhook']);
    }

    private function pagina(): string
    {
        $this->em->clear();
        $this->client->request('GET', '/contribua');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
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
        $tenant = (new Tenant())->setName('Escritório CORA ' . $nome . ' ' . uniqid());
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
