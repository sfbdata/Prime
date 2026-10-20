<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\Bacen\BancoDoBrasil;
use App\Contribua\Banco\Bacen\BancoInter;
use App\Contribua\Banco\Bacen\BancoPixBacen;
use App\Contribua\Banco\Bacen\BancoPixPadrao;
use App\Contribua\Banco\Bacen\ClientePixBacen;
use App\Contribua\Banco\Bacen\CredenciaisBacen;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Contribua\Banco\PspBacenFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Inter, Banco do Brasil e "Outro" contra um PSP falso com as respostas da API Pix do BACEN (C4b, D2, D3, D9). */
#[CoversClass(BancoPixBacen::class)]
#[CoversClass(BancoInter::class)]
#[CoversClass(BancoDoBrasil::class)]
#[CoversClass(BancoPixPadrao::class)]
#[CoversClass(ClientePixBacen::class)]
#[CoversClass(CredenciaisBacen::class)]
final class BancosBacenTest extends TestCase
{
    private const SECRET = 'segredo-do-cliente-123';
    private const APP_KEY = 'appkey-bb-987';

    private PspBacenFalso $psp;
    private ArrayAdapter $cache;
    private CofreDeCertificado $cofre;
    private IntegracaoBancaria $integracao;

    protected function setUp(): void
    {
        $this->psp = new PspBacenFalso();
        $this->cache = new ArrayAdapter();
        $this->cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        $this->integracao = new IntegracaoBancaria();
    }

    #[TestDox('Inter: token em /oauth/v2/token com client_id, client_secret, escopos e grant_type no corpo, por mTLS; depois a consulta leve; nada em disco depois')]
    public function testInterTestarConexao(): void
    {
        $this->configurar('inter');

        $r = $this->banco('inter')->testarConexao();

        self::assertTrue($r->conectou, $r->mensagem);
        self::assertSame('Conexão com o Banco Inter confirmada.', $r->mensagem);
        $token = $this->psp->pedidos[0];
        self::assertSame(BancoInter::TOKEN_SANDBOX, $token['url']);
        $corpo = (string) $token['opcoes']['body'];
        self::assertStringContainsString('grant_type=client_credentials', $corpo);
        self::assertStringContainsString('client_id=cliente-1', $corpo);
        self::assertStringContainsString('client_secret=' . self::SECRET, $corpo);
        self::assertStringContainsString('scope=cob.write+cob.read+pix.read+webhook.read+webhook.write', $corpo);
        self::assertNull($this->psp->cabecalho($token['opcoes'], 'authorization'), 'o Inter recebe as credenciais no corpo, não em Basic');
        foreach ($this->psp->pedidos as $p) {
            self::assertArrayHasKey('local_cert', $p['opcoes'], 'mTLS em toda chamada: ' . $p['url']);
            self::assertArrayHasKey('local_pk', $p['opcoes']);
            self::assertFileDoesNotExist($p['opcoes']['local_cert'], 'o certificado em claro não fica em disco');
            self::assertFileDoesNotExist($p['opcoes']['local_pk']);
        }
        $lista = $this->psp->pedidos[array_key_last($this->psp->pedidos)];
        self::assertSame('GET', $lista['metodo']);
        self::assertStringStartsWith(BancoInter::BASE_SANDBOX . '/cob?', $lista['url']);
        self::assertStringContainsString('inicio=', $lista['url']);
    }

    #[TestDox('Inter em produção usa as URLs de produção')]
    public function testInterProducao(): void
    {
        $this->configurar('inter', 'producao');
        $this->banco('inter')->testarConexao();

        self::assertSame(BancoInter::TOKEN_PRODUCAO, $this->psp->pedidos[0]['url']);
        self::assertStringStartsWith(BancoInter::BASE_PRODUCAO . '/cob?', $this->psp->pedidos[array_key_last($this->psp->pedidos)]['url']);
    }

    #[TestDox('BB: token com Authorization Basic client_id:client_secret (sem o segredo no corpo); a app key vai em gw-dev-app-key na homologação e não vai no token')]
    public function testBancoDoBrasilHomologacao(): void
    {
        $this->configurar('bb');

        self::assertTrue($this->banco('bb')->testarConexao()->conectou);

        $token = $this->psp->pedidos[0];
        self::assertSame(BancoDoBrasil::TOKEN_HOMOLOGACAO, $token['url']);
        self::assertSame('Basic ' . base64_encode('cliente-1:' . self::SECRET), $this->psp->cabecalho($token['opcoes'], 'authorization'));
        self::assertStringNotContainsString(self::SECRET, (string) $token['opcoes']['body']);
        self::assertStringNotContainsString(self::APP_KEY, $token['url']);
        $lista = $this->psp->pedidos[1];
        self::assertStringStartsWith(BancoDoBrasil::BASE_HOMOLOGACAO . '/cob?', $lista['url']);
        parse_str((string) parse_url($lista['url'], \PHP_URL_QUERY), $query);
        self::assertSame(self::APP_KEY, $query['gw-dev-app-key'] ?? null);
        self::assertArrayNotHasKey('gw-app-key', $query);
        self::assertSame('Bearer tok-1', $this->psp->cabecalho($lista['opcoes'], 'authorization'));
    }

    #[TestDox('BB em produção: URLs de produção e a app key em gw-app-key, também na cobrança')]
    public function testBancoDoBrasilProducao(): void
    {
        $this->configurar('bb', 'producao');

        $this->banco('bb')->criarCobranca($this->pedido(2000));

        self::assertSame(BancoDoBrasil::TOKEN_PRODUCAO, $this->psp->pedidos[0]['url']);
        $cob = $this->psp->pedidos[1];
        self::assertStringStartsWith(BancoDoBrasil::BASE_PRODUCAO . '/cob/', $cob['url']);
        parse_str((string) parse_url($cob['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['gw-app-key' => self::APP_KEY], $query);
    }

    #[TestDox('Outro: URLs da configuração e token por Basic')]
    public function testOutro(): void
    {
        $this->configurar('outro');

        self::assertTrue($this->banco('outro')->testarConexao()->conectou);

        self::assertSame('https://psp.exemplo.com.br/oauth/token', $this->psp->pedidos[0]['url']);
        self::assertSame('Basic ' . base64_encode('cliente-1:' . self::SECRET), $this->psp->cabecalho($this->psp->pedidos[0]['opcoes'], 'authorization'));
        self::assertStringStartsWith('https://psp.exemplo.com.br/api/pix/v2/cob?', $this->psp->pedidos[1]['url']);
    }

    #[TestDox('Cobrança: PUT /cob/{txid} com expiração, devedor (CNPJ e nome), valor "20.00", chave PIX, solicitação e o nosso código; o id no banco é o txid')]
    public function testCriarCobranca(): void
    {
        $this->configurar('inter');
        $pedido = $this->pedido(2000);

        $c = $this->banco('inter')->criarCobranca($pedido);

        $put = $this->psp->pedidos[1];
        self::assertSame('PUT', $put['metodo']);
        self::assertSame(BancoInter::BASE_SANDBOX . '/cob/' . BancoPixBacen::txid($pedido), $put['url']);
        self::assertSame(BancoPixBacen::txid($pedido), $c->idNoBanco);
        self::assertStringStartsWith('000201', $c->copiaECola);
        $corpo = json_decode((string) $put['opcoes']['body'], true);
        self::assertSame(['cnpj' => '12345678000199', 'nome' => 'Escritório Teste'], $corpo['devedor']);
        self::assertSame(['original' => '20.00'], $corpo['valor']);
        self::assertSame('pix@bluejus.com.br', $corpo['chave']);
        self::assertSame('Apoio ao BlueJus, conta BJ000001-1', $corpo['solicitacaoPagador']);
        self::assertSame([['nome' => 'Código', 'valor' => 'BJ000001-1']], $corpo['infoAdicionais']);
        self::assertIsInt($corpo['calendario']['expiracao']);
        self::assertGreaterThan(3600, $corpo['calendario']['expiracao']);
        self::assertArrayHasKey('local_cert', $put['opcoes']);
    }

    #[TestDox('txid: alfanumérico de 26 a 35, determinístico (mesma tentativa, mesmo txid); outra chave ou outro código, outro txid')]
    public function testFormatoDoTxid(): void
    {
        $a = new PedidoDeCobrancaPix('BJ000001-1', 'chave-a', 'E', '12345678000199', 2000, new \DateTimeImmutable('+7 days'));
        $b = new PedidoDeCobrancaPix('BJ000001-1', 'chave-b', 'E', '12345678000199', 2000, new \DateTimeImmutable('+7 days'));
        $c = new PedidoDeCobrancaPix('BJ000001-2', 'chave-a', 'E', '12345678000199', 2000, new \DateTimeImmutable('+7 days'));

        self::assertMatchesRegularExpression('/^[a-zA-Z0-9]{26,35}$/', BancoPixBacen::txid($a));
        self::assertSame(BancoPixBacen::txid($a), BancoPixBacen::txid(new PedidoDeCobrancaPix('BJ000001-1', 'chave-a', 'Outro nome', '1', 5000, new \DateTimeImmutable())));
        self::assertNotSame(BancoPixBacen::txid($a), BancoPixBacen::txid($b));
        self::assertNotSame(BancoPixBacen::txid($a), BancoPixBacen::txid($c));
    }

    #[TestDox('Repetir a mesma tentativa (o banco já criou): o txid já existe, a cobrança ativa é reaproveitada pela consulta')]
    public function testRepeticao(): void
    {
        $this->configurar('inter');
        $primeira = $this->banco('inter')->criarCobranca($this->pedido(2000, 'chave-fixa'));

        $segunda = $this->banco('inter')->criarCobranca($this->pedido(2000, 'chave-fixa'));

        self::assertSame($primeira->idNoBanco, $segunda->idNoBanco);
        self::assertSame($primeira->copiaECola, $segunda->copiaECola);
        self::assertCount(1, $this->psp->cobs);
    }

    #[TestDox('Consulta: ATIVA não é paga; CONCLUIDA é paga com o valor e a hora de pix[0] e o nosso código; REMOVIDA_* é cancelada')]
    public function testConsultar(): void
    {
        $this->configurar('bb');
        $banco = $this->banco('bb');
        $c = $banco->criarCobranca($this->pedido(2000));
        self::assertFalse($banco->consultar($c->idNoBanco)->paga);

        $this->psp->pagar($c->idNoBanco, '19.9');
        $pago = $banco->consultar($c->idNoBanco);

        self::assertTrue($pago->paga);
        self::assertSame(1990, $pago->valorPagoCentavos);
        self::assertSame('2026-10-19 14:30', $pago->pagaEm?->format('Y-m-d H:i'));
        self::assertSame('BJ000001-1', $pago->codigo);
        self::assertFalse($pago->cancelada);

        $this->psp->cobs[$c->idNoBanco]['status'] = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
        self::assertTrue($banco->consultar($c->idNoBanco)->cancelada);
    }

    #[TestDox('Consulta paga sem pix[0]: o valor é o valor.original')]
    public function testValorOriginal(): void
    {
        $this->configurar('inter');
        $c = $this->banco('inter')->criarCobranca($this->pedido(5000));
        $this->psp->cobs[$c->idNoBanco]['status'] = 'CONCLUIDA';

        self::assertSame(5000, $this->banco('inter')->consultar($c->idNoBanco)->valorPagoCentavos);
    }

    #[TestDox('Token vencido (401): descarta o guardado, pede outro e repete a chamada uma vez; o token é reaproveitado entre chamadas')]
    public function testTokenVencido(): void
    {
        $this->configurar('inter');
        $banco = $this->banco('inter');
        $c = $banco->criarCobranca($this->pedido(2000));
        $banco->consultar($c->idNoBanco);
        self::assertSame(1, $this->psp->tokensEmitidos, 'um token para as duas chamadas');
        $this->psp->tokensExpirados = 1;

        self::assertFalse($banco->consultar($c->idNoBanco)->paga);
        self::assertSame(2, $this->psp->tokensEmitidos);
    }

    #[TestDox('5xx e 429 são falha passageira; 400 é recusa do pedido (não passageira), com a razão do padrão')]
    public function testFalhas(): void
    {
        $this->configurar('inter');
        foreach ([500 => true, 503 => true, 429 => true, 403 => true, 400 => false] as $status => $passageira) {
            $this->psp->statusDaCriacao = $status;
            try {
                $this->banco('inter')->criarCobranca($this->pedido(2000));
                self::fail('Devia falhar com ' . $status);
            } catch (FalhaNoBanco $e) {
                self::assertSame($passageira, $e->passageira, (string) $status);
                self::assertStringContainsString('O banco respondeu ' . $status, $e->getMessage());
            }
        }
        self::assertStringContainsString('falha simulada 400', $e->getMessage());
    }

    #[TestDox('Credenciais recusadas: "O Banco do Brasil recusou a conexão" com o motivo, sem o segredo; escopo negado na consulta tem mensagem própria')]
    public function testRecusas(): void
    {
        $this->configurar('bb');
        $this->psp->recusarToken = true;

        $r = $this->banco('bb')->testarConexao();

        self::assertFalse($r->conectou);
        self::assertSame('O Banco do Brasil recusou a conexão: O banco respondeu 401: Client authentication failed.', $r->mensagem);

        $this->psp->recusarToken = false;
        $this->psp->statusDaLista = 403;
        $r = $this->banco('bb')->testarConexao();
        self::assertFalse($r->conectou);
        self::assertStringStartsWith('O Banco do Brasil aceitou as credenciais, mas a API Pix recusou a consulta: O banco respondeu 403', $r->mensagem);
    }

    #[TestDox('Sem resposta do banco: a mensagem não leva a app key nem a query string da URL')]
    public function testSemRespostaNaoVazaSegredo(): void
    {
        $this->configurar('bb');
        $http = new MockHttpClient(static function (string $metodo, string $url): MockResponse {
            if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/token')) {
                return new MockResponse('{"access_token":"t","expires_in":600}', ['http_code' => 200]);
            }

            return new MockResponse([new \RuntimeException('Idle timeout reached for "' . $url . '".')]);
        });
        $banco = new BancoDoBrasil(new ClientePixBacen($http, $this->cache, $this->cofre), $this->repo(), $this->cofre);

        $r = $banco->testarConexao();

        self::assertFalse($r->conectou);
        self::assertStringContainsString('Sem resposta do banco', $r->mensagem);
        self::assertStringNotContainsString(self::APP_KEY, $r->mensagem);
        self::assertStringNotContainsString(self::SECRET, $r->mensagem);
    }

    #[TestDox('Configuração incompleta: cada banco diz o que falta antes de chamar')]
    public function testFaltas(): void
    {
        self::assertSame('Informe o Client ID e envie o certificado antes de testar.', $this->banco('inter')->testarConexao()->mensagem);
        $inter = $this->banco('inter');
        self::assertSame('Informe o Client Secret fornecido pelo banco.', $inter->faltaNaConfiguracao(false, 'x', false, '', ''));
        self::assertSame('Informe a chave PIX da conta que recebe.', $inter->faltaNaConfiguracao(true, ' ', false, '', ''));
        self::assertNull($inter->faltaNaConfiguracao(true, 'x', false, '', ''));
        self::assertSame('Informe a app key do Banco do Brasil.', $this->banco('bb')->faltaNaConfiguracao(true, 'x', false, '', ''));
        self::assertSame('Informe a URL base da API Pix e a URL do token do banco.', $this->banco('outro')->faltaNaConfiguracao(true, 'x', false, 'https://a', ''));
        self::assertSame([], $this->psp->pedidos);
    }

    #[TestDox('Valores: reais no formato do padrão e de volta em centavos')]
    public function testValores(): void
    {
        self::assertSame('20.00', BancoPixBacen::reais(2000));
        self::assertSame('0.05', BancoPixBacen::reais(5));
        self::assertSame('1234.56', BancoPixBacen::reais(123456));
        self::assertSame(1990, BancoPixBacen::centavos('19.9'));
        self::assertSame(2000, BancoPixBacen::centavos('20'));
        self::assertSame(0, BancoPixBacen::centavos('abc'));
        self::assertSame(0, BancoPixBacen::centavos(20));
    }

    private function configurar(string $banco, string $ambiente = 'sandbox'): void
    {
        $par = ParDeCertificado::novo();
        $this->integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), ['cert.pem', 'chave.key'], new User());
        $this->integracao->escolherBanco($banco);
        $this->integracao->definirEnderecos('pix@bluejus.com.br', 'outro' === $banco ? 'https://psp.exemplo.com.br/api/pix/v2/' : '', 'outro' === $banco ? 'https://psp.exemplo.com.br/oauth/token' : '');
        $this->integracao->guardarSegredos($this->cofre->cifrar(self::SECRET), 'bb' === $banco ? $this->cofre->cifrar(self::APP_KEY) : null);
        $this->integracao->configurar($ambiente, 'cliente-1', [], new User());
    }

    private function pedido(int $centavos, ?string $chave = null): PedidoDeCobrancaPix
    {
        return new PedidoDeCobrancaPix('BJ000001-1', $chave ?? bin2hex(random_bytes(8)), 'Escritório Teste', '12345678000199', $centavos, new \DateTimeImmutable('+7 days'));
    }

    private function banco(string $codigo): BancoPixBacen
    {
        $cliente = new ClientePixBacen($this->psp->cliente(), $this->cache, $this->cofre);

        return match ($codigo) {
            'inter' => new BancoInter($cliente, $this->repo(), $this->cofre),
            'bb' => new BancoDoBrasil($cliente, $this->repo(), $this->cofre),
            default => new BancoPixPadrao($cliente, $this->repo(), $this->cofre),
        };
    }

    private function repo(): IntegracaoBancariaRepository
    {
        $repo = $this->createStub(IntegracaoBancariaRepository::class);
        $repo->method('atual')->willReturn($this->integracao);

        return $repo;
    }
}
