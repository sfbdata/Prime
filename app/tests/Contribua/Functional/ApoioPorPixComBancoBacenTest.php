<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Banco\Bacen\BancoPixBacen;
use App\Contribua\Banco\Bacen\ClientePixBacen;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Command\ConciliarCobrancasPixCommand;
use App\Contribua\Controller\IntegracaoBancariaController;
use App\Contribua\Controller\WebhookDoBancoController;
use App\Contribua\Message\ReconsultarCobrancaNoBanco;
use App\Contribua\MessageHandler\ReconsultarCobrancaNoBancoHandler;
use App\Contribua\UseCase\ConfirmarCobrancaPeloBancoUseCase;
use App\Contribua\UseCase\SalvarIntegracaoBancariaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Contribua\Banco\PspBacenFalso;
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
 * C4b/C4c de ponta a ponta contra um PSP falso da API Pix do BACEN: o Master escolhe o Inter (ou o BB) e
 * preenche os campos extras; os segredos não voltam à tela; a conta recebe o PIX dinâmico; o aviso do
 * banco (corpo `{"pix": [...]}`) só enfileira a reconsulta de uma cobrança nossa; a reconsulta confirma; a
 * conciliação vale para o banco ativo. Spec: docs/specs/contribua-c4b-bancos.md.
 */
#[CoversClass(IntegracaoBancariaController::class)]
#[CoversClass(WebhookDoBancoController::class)]
#[CoversClass(SalvarIntegracaoBancariaUseCase::class)]
#[CoversClass(ConciliarCobrancasPixCommand::class)]
final class ApoioPorPixComBancoBacenTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';
    private const SECRET = 'segredo-inter-XYZ-123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PspBacenFalso $psp;
    private Tenant $escritorio;
    private User $primario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->psp = new PspBacenFalso();
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        static::getContainer()->set(CofreDeCertificado::class, $cofre);
        static::getContainer()->set(ClientePixBacen::class, new ClientePixBacen($this->psp->cliente(), new ArrayAdapter(), $cofre));
        $this->instalarCsrf();
        static::getContainer()->get('cache.app')->deleteItem('contribua_banco_em_pausa');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A', '12345678000199');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Só o Master escolhe o banco; Inter salvo com Client Secret cifrado e chave PIX; o segredo não volta no JSON nem no HTML; a tela mostra os quatro bancos do desenho habilitados')]
    public function testMasterConfiguraInter(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/integracao-bancaria/salvar', ['_token' => self::TOKEN, 'clientId' => 'x', 'banco' => 'inter', 'clientSecret' => 'roubo']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertSame('Informe o Client Secret fornecido pelo banco.', $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'sandbox', 'clientId' => 'c1', 'banco' => 'inter', 'chavePix' => 'x'])['erro']);
        self::assertSame('Banco não disponível.', $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'sandbox', 'clientId' => 'c1', 'banco' => 'itau'])['erro']);
        $this->conectar('inter');
        $respostas = (string) $this->client->getResponse()->getContent();

        $linha = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT banco, estado, chave_pix, client_secret_cifrado FROM contribua_integracao_bancaria');
        self::assertSame('inter', $linha['banco']);
        self::assertSame('ok', $linha['estado']);
        self::assertSame('pix@bluejus.com.br', $linha['chave_pix']);
        self::assertStringNotContainsString(self::SECRET, (string) base64_decode((string) $linha['client_secret_cifrado']));
        self::assertStringNotContainsString(self::SECRET, $respostas);
        $html = $this->pagina();
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString((string) $linha['client_secret_cifrado'], $html);
        self::assertStringContainsString('Definido. Deixe em branco para manter.', $html);
        self::assertStringContainsString('value="pix@bluejus.com.br"', $html);
        self::assertMatchesRegularExpression('/<option value="inter" selected>Banco Inter \(077\)<\/option>/', $html);
        preg_match_all('/<option value="(cora|inter|bb|outro)"[^>]*>([^<]+)<\/option>/', $html, $opcoes);
        self::assertSame(['Cora SCFI (403)', 'Banco Inter (077)', 'Banco do Brasil (001)', 'Outro (API PIX padrão BACEN)'], $opcoes[2]);
        self::assertStringNotContainsString('disabled>Banco', $html);
        self::assertMatchesRegularExpression('/data-cc-para="bb" hidden>APP KEY/', $html, 'a app key só aparece no BB');
    }

    #[TestDox('PIX da conta pelo Inter; aviso do banco com txid nosso enfileira a reconsulta, que confirma um apoio de origem "inter"; repetido, txid alheio e banco desconhecido não fazem nada')]
    public function testWebhookBacenConfirma(): void
    {
        $txid = $this->cobrancaPaga();

        $this->aviso('inter', $txid, 'E1');
        self::assertResponseIsSuccessful();
        $primeira = $this->enfileiradas();
        $this->aviso('inter', $txid, 'E1');
        self::assertSame([], $this->enfileiradas(), 'aviso repetido não enfileira');
        $this->aviso('inter', 'BJ' . str_repeat('a', 30), 'E2');
        self::assertSame([], $this->enfileiradas(), 'txid que não é nosso não gera trabalho');
        $this->aviso('bb', $txid, 'E3');
        self::assertSame([], $this->enfileiradas(), 'cobrança de outro banco não vale');
        $this->aviso('itau', $txid, 'E4');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->enfileiradas(), 'banco desconhecido não faz nada');
        $this->client->request('POST', '/api/webhooks/inter/pix', [], [], ['CONTENT_TYPE' => 'application/json'], 'isto não é json');
        self::assertResponseIsSuccessful();

        $banco = static::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $banco->fetchOne("SELECT COUNT(*) FROM contribua_notificacao_recebida WHERE origem IN ('inter', 'bb', 'itau')"), 'só o aviso nosso é registrado');
        self::assertCount(1, $primeira);
        self::assertInstanceOf(ReconsultarCobrancaNoBanco::class, $primeira[0]);
        self::assertSame(['inter', $txid, 'E1'], [$primeira[0]->banco, $primeira[0]->idNoBanco, $primeira[0]->idEvento]);
        $handler = static::getContainer()->get(ReconsultarCobrancaNoBancoHandler::class);
        $handler($primeira[0]);
        $handler($primeira[0]);

        $apoios = $banco->fetchAllAssociative('SELECT origem, id_cora, txid, valor_centavos FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]);
        self::assertCount(1, $apoios);
        self::assertSame('inter', $apoios[0]['origem']);
        self::assertSame($txid, $apoios[0]['id_cora'], 'o id no banco é o txid');
        self::assertStringStartsWith('BJ' . str_pad((string) $this->escritorio->getId(), 6, '0', \STR_PAD_LEFT) . '-', $apoios[0]['txid']);
        self::assertSame(2000, (int) $apoios[0]['valor_centavos']);
    }

    #[TestDox('O valor do aviso não é usado: o apoio leva o valor que a reconsulta devolve; o endereço com /pix/pix (padrão BACEN) também é aceito')]
    public function testValorDaReconsulta(): void
    {
        $txid = $this->cobrancaPaga();

        $this->client->request('POST', '/api/webhooks/inter/pix/pix', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['pix' => [['endToEndId' => 'E9', 'txid' => $txid, 'valor' => '999999.00', 'horario' => '2026-10-19T14:30:00Z']]]));
        $fila = $this->enfileiradas();
        self::assertCount(1, $fila);
        static::getContainer()->get(ReconsultarCobrancaNoBancoHandler::class)($fila[0]);

        self::assertSame(2000, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT valor_centavos FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
    }

    #[TestDox('Conciliação do banco ativo: app:contribua:conciliar confirma no Inter; app:cora:conciliar continua como apelido')]
    public function testConciliar(): void
    {
        $this->cobrancaPaga();
        $aplicacao = new Application(static::$kernel);
        self::assertSame($aplicacao->find('app:contribua:conciliar'), $aplicacao->find('app:cora:conciliar'));

        $comando = new CommandTester($aplicacao->find('app:contribua:conciliar'));
        $comando->execute([]);

        self::assertStringContainsString('1 apoio(s) confirmado(s)', $comando->getDisplay());
        self::assertSame('inter', static::getContainer()->get(Connection::class)->fetchOne('SELECT origem FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
    }

    #[TestDox('BB: certificado A1 em .pfx com senha é aceito com a senha e recusado sem ela; app key cifrada; a app key e a senha não voltam')]
    public function testBancoDoBrasilComPfx(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $par = ParDeCertificado::novo();
        openssl_pkcs12_export($par['certificado'], $pfx, $par['chave'], 'senha-a1');
        $arquivo = sys_get_temp_dir() . '/bj-pfx-' . uniqid() . '.pfx';
        file_put_contents($arquivo, $pfx);

        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [new UploadedFile($arquivo, 'empresa.pfx', null, null, true)]]);
        self::assertStringContainsString('informe a senha do certificado', (string) json_decode((string) $this->client->getResponse()->getContent(), true)['erro']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN, 'senhaDoCertificado' => 'senha-a1'], ['arquivos' => [new UploadedFile($arquivo, 'empresa.pfx', null, null, true)]]);
        $resposta = (string) $this->client->getResponse()->getContent();
        self::assertTrue(json_decode($resposta, true)['ok'], $resposta);
        self::assertStringNotContainsString('senha-a1', $resposta);

        $salvo = $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'sandbox', 'clientId' => 'c1', 'banco' => 'bb', 'clientSecret' => self::SECRET, 'chavePix' => 'pix@bluejus.com.br', 'appKey' => 'app-key-do-bb']);
        self::assertTrue($salvo['ok'], (string) json_encode($salvo));
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
        $html = $this->pagina();
        self::assertStringNotContainsString('app-key-do-bb', $html);
        self::assertStringNotContainsString('senha-a1', $html);
        self::assertStringContainsString('Definida. Deixe em branco para manter.', $html);
        $urlDaApi = $this->psp->pedidos[array_key_last($this->psp->pedidos)]['url'];
        self::assertStringContainsString('gw-dev-app-key=app-key-do-bb', $urlDaApi);
    }

    #[TestDox('Master troca do Inter para o BB com uma cobrança do Inter aberta e paga: o aviso do Inter não registra nem enfileira, a reconsulta e a conciliação não chamam banco nenhum, nada é confirmado')]
    public function testTrocaDeBancoNaoMandaSegredoAoOutro(): void
    {
        $txid = $this->cobrancaPaga();
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        self::assertSame('Ao trocar de banco ou de endereço, informe de novo o Client Secret.', $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'producao', 'clientId' => 'c-bb', 'banco' => 'bb', 'chavePix' => 'x', 'appKey' => 'k'])['erro']);
        self::assertTrue($this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => 'producao', 'clientId' => 'c-bb', 'banco' => 'bb', 'clientSecret' => 'segredo-bb', 'chavePix' => 'x', 'appKey' => 'k'])['ok']);
        $chamadas = count($this->psp->pedidos);

        $this->aviso('inter', $txid, 'E-depois-da-troca');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->enfileiradas());
        static::getContainer()->get(ReconsultarCobrancaNoBancoHandler::class)(new ReconsultarCobrancaNoBanco('inter', $txid, 'E-fila-antiga'));
        self::assertFalse(static::getContainer()->get(ConfirmarCobrancaPeloBancoUseCase::class)->executar('inter', $txid));
        (new CommandTester((new Application(static::$kernel))->find('app:contribua:conciliar')))->execute([]);

        self::assertCount($chamadas, $this->psp->pedidos, 'nenhuma chamada HTTP sai depois da troca');
        $banco = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM contribua_apoio WHERE tenant_id = :t', ['t' => $this->escritorio->getId()]));
        self::assertSame(0, (int) $banco->fetchOne("SELECT COUNT(*) FROM contribua_notificacao_recebida WHERE origem = 'inter'"));
        self::assertSame('aberta', $banco->fetchOne('SELECT situacao FROM contribua_cobranca_pix WHERE id_no_banco = :i', ['i' => $txid]));
    }

    #[TestDox('Aviso com 51 itens: só os 50 primeiros são lidos (o 51º, mesmo sendo cobrança nossa, é ignorado)')]
    public function testCorteEm50(): void
    {
        $txid = $this->cobrancaPaga();
        $itens = [];
        for ($n = 0; $n < 50; ++$n) {
            $itens[] = ['endToEndId' => 'E-lixo-' . $n, 'txid' => 'BJ' . str_pad((string) $n, 30, 'z'), 'valor' => '1.00'];
        }
        $itens[] = ['endToEndId' => 'E-51', 'txid' => $txid, 'valor' => '20.00'];

        $this->client->request('POST', '/api/webhooks/inter/pix', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['pix' => $itens]));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->enfileiradas());
        array_pop($itens);
        array_pop($itens);
        $itens[] = ['endToEndId' => 'E-50', 'txid' => $txid, 'valor' => '20.00'];
        $this->client->request('POST', '/api/webhooks/inter/pix', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['pix' => $itens]));
        self::assertCount(1, $this->enfileiradas(), 'o 50º ainda é lido');
    }

    /** @return list<object> */
    private function enfileiradas(): array
    {
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($e) => $e->getMessage(), $fila->getSent()));
    }

    /** Master configura o Inter; a conta gera a cobrança de R$ 20 e o PSP a dá por paga. Devolve o txid. */
    private function cobrancaPaga(): string
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->conectar('inter', 'producao');
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $pix = $this->pix(20);
        self::assertStringContainsString('pix.example.com', $pix['codigo']);
        $txid = (string) array_key_first($this->psp->cobs);
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9]{26,35}$/', $txid);
        self::assertSame(BancoPixBacen::TAMANHO_TXID, strlen($txid));
        $this->psp->pagar($txid);

        return $txid;
    }

    private function conectar(string $banco, string $ambiente = 'sandbox'): void
    {
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/inter.crt', $par['certificado']);
        file_put_contents($dir . '/inter.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [
            new UploadedFile($dir . '/inter.crt', 'inter.crt', null, null, true),
            new UploadedFile($dir . '/inter.key', 'inter.key', null, null, true),
        ]]);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
        $salvo = $this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => $ambiente, 'clientId' => 'cliente-1', 'banco' => $banco, 'clientSecret' => self::SECRET, 'chavePix' => 'pix@bluejus.com.br', 'recursos' => ['cob' => '1', 'conc' => '1', 'bol' => '0', 'rel' => '1']]);
        self::assertTrue($salvo['ok'], (string) json_encode($salvo));
        $testado = $this->post('/contribua/integracao-bancaria/testar');
        self::assertTrue($testado['conectado'], (string) json_encode($testado));
    }

    private function aviso(string $banco, string $txid, string $e2e): void
    {
        $this->client->request('POST', '/api/webhooks/' . $banco . '/pix', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['pix' => [['endToEndId' => $e2e, 'txid' => $txid, 'valor' => '20.00', 'horario' => '2026-10-19T14:30:00.000Z']]]));
    }

    /** @return array{codigo: string, valido: bool} */
    private function pix(int $reais): array
    {
        $this->client->request('GET', '/contribua/pix?valor=' . $reais);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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
        $tenant = (new Tenant())->setName('Escritório BACEN ' . $nome . ' ' . uniqid());
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
