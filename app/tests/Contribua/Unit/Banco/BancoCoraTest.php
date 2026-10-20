<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\BancoCora;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(BancoCora::class)]
#[CoversClass(ClienteCora::class)]
final class BancoCoraTest extends TestCase
{
    private CoraFalsa $cora;
    private ArrayAdapter $cache;
    private CofreDeCertificado $cofre;
    private IntegracaoBancaria $integracao;

    protected function setUp(): void
    {
        $this->cora = new CoraFalsa();
        $this->cache = new ArrayAdapter();
        $this->cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        $this->integracao = new IntegracaoBancaria();
    }

    #[TestDox('Testar conexão: sem Client ID ou certificado avisa; com eles pede o token por mTLS (client_credentials) no stage')]
    public function testTestarConexao(): void
    {
        self::assertSame('Informe o Client ID e envie o certificado antes de testar.', $this->sut()->testarConexao()->mensagem);

        $this->configurar();
        $r = $this->sut()->testarConexao();

        self::assertTrue($r->conectou);
        $token = $this->cora->pedidos[0];
        self::assertSame('https://matls-clients.api.stage.cora.com.br/token', $token['url']);
        self::assertStringContainsString('grant_type=client_credentials', (string) $token['opcoes']['body']);
        self::assertStringContainsString('client_id=cliente-1', (string) $token['opcoes']['body']);
        self::assertArrayHasKey('local_cert', $token['opcoes']);
        self::assertFileDoesNotExist($token['opcoes']['local_cert'], 'o certificado em claro não fica em disco depois da chamada');
    }

    #[TestDox('Credenciais recusadas: "A Cora recusou a conexão" com o motivo')]
    public function testRecusa(): void
    {
        $this->configurar();
        $this->cora->recusarToken = true;

        $r = $this->sut()->testarConexao();

        self::assertFalse($r->conectou);
        self::assertStringStartsWith('A Cora recusou a conexão: A Cora respondeu 401', $r->mensagem);
    }

    #[TestDox('Cobrança PIX: POST /v2/invoices/ só PIX, com Idempotency-Key, CNPJ, valor e vencimento; devolve o id e o copia e cola; o token é reaproveitado')]
    public function testCriarCobranca(): void
    {
        $this->configurar();
        $sut = $this->sut();

        $c = $sut->criarCobranca($this->pedido(2000));
        $sut->criarCobranca($this->pedido(5000));

        self::assertStringStartsWith('inv_', $c->idNoBanco);
        self::assertStringStartsWith('000201', $c->copiaECola);
        self::assertSame(1, $this->cora->tokensEmitidos, 'um token só para as duas cobranças');
        $pedido = $this->cora->pedidos[1];
        $corpo = json_decode((string) $pedido['opcoes']['body'], true);
        self::assertSame('chave-1', $this->cora->cabecalho($pedido['opcoes'], 'idempotency-key'));
        self::assertSame(['PIX'], $corpo['payment_forms']);
        self::assertSame(['identity' => '12345678000199', 'type' => 'CNPJ'], $corpo['customer']['document']);
        self::assertSame(2000, $corpo['services'][0]['amount']);
        self::assertSame('2030-03-17', $corpo['payment_terms']['due_date']);
        self::assertSame('BJ000001-1', $corpo['code']);
    }

    #[TestDox('Valor abaixo do mínimo da Cora (R$ 5): falha do banco com o motivo da Cora')]
    public function testValorMinimo(): void
    {
        $this->configurar();
        $this->expectException(FalhaNoBanco::class);
        $this->expectExceptionMessage('must be greater than or equal to 500');

        $this->sut()->criarCobranca($this->pedido(300));
    }

    #[TestDox('Token vencido (401): pede outro e repete a chamada uma vez')]
    public function testTokenVencido(): void
    {
        $this->configurar();
        $sut = $this->sut();
        $c = $sut->criarCobranca($this->pedido(2000));
        $this->cora->tokensExpirados = 1;

        $situacao = $sut->consultar($c->idNoBanco);

        self::assertFalse($situacao->paga);
        self::assertSame(2, $this->cora->tokensEmitidos);
    }

    #[TestDox('Consulta: PAID vira paga com valor e data; OPEN não; desconhecida é falha')]
    public function testConsultar(): void
    {
        $this->configurar();
        $sut = $this->sut();
        $c = $sut->criarCobranca($this->pedido(2000));
        self::assertFalse($sut->consultar($c->idNoBanco)->paga);

        $this->cora->status[$c->idNoBanco] = 'PAID';
        $pago = $sut->consultar($c->idNoBanco);

        self::assertTrue($pago->paga);
        self::assertSame(2000, $pago->valorPagoCentavos);
        self::assertSame('2026-10-19', $pago->pagaEm?->format('Y-m-d'));
        $this->expectException(FalhaNoBanco::class);
        $sut->consultar('inv_que_nao_existe');
    }

    #[TestDox('Consulta paga com hora em UTC: vira o fuso do PHP (2026-10-31T01:30:00Z = 30/10 22:30 em São Paulo)')]
    public function testDataComFusoViraFusoDoPhp(): void
    {
        $this->configurar();
        $sut = $this->sut();
        $c = $sut->criarCobranca($this->pedido(2000));
        $this->cora->status[$c->idNoBanco] = 'PAID';
        $this->cora->dataDoPagamento = '2026-10-31T01:30:00Z';

        $pago = $sut->consultar($c->idNoBanco);

        self::assertSame('2026-10-30 22:30', $pago->pagaEm?->format('Y-m-d H:i'));
        self::assertSame(date_default_timezone_get(), $pago->pagaEm?->getTimezone()->getName());
    }

    #[TestDox('Consulta paga só com a data: a data fica como veio, no fuso do PHP')]
    public function testSoDataFicaComoVeio(): void
    {
        $this->configurar();
        $sut = $this->sut();
        $c = $sut->criarCobranca($this->pedido(2000));
        $this->cora->status[$c->idNoBanco] = 'PAID';
        $this->cora->dataDoPagamento = '2026-10-31';

        $pago = $sut->consultar($c->idNoBanco);

        self::assertSame('2026-10-31 00:00', $pago->pagaEm?->format('Y-m-d H:i'));
        self::assertSame(date_default_timezone_get(), $pago->pagaEm?->getTimezone()->getName());
    }

    #[TestDox('Produção usa a base de produção da Integração Direta')]
    public function testProducao(): void
    {
        $this->configurar('producao');
        $this->sut()->testarConexao();

        self::assertSame('https://matls-clients.api.cora.com.br/token', $this->cora->pedidos[0]['url']);
    }

    private function configurar(string $ambiente = 'sandbox'): void
    {
        $par = ParDeCertificado::novo();
        $this->integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), ['cert.pem', 'chave.key'], new User());
        $this->integracao->configurar($ambiente, 'cliente-1', [], new User());
    }

    private function pedido(int $centavos): PedidoDeCobrancaPix
    {
        static $n = 0;

        return new PedidoDeCobrancaPix('BJ000001-1', 'chave-' . ++$n, 'Escritório Teste', '12345678000199', $centavos, new \DateTimeImmutable('2030-03-17'));
    }

    private function sut(): BancoCora
    {
        $repo = $this->createStub(IntegracaoBancariaRepository::class);
        $repo->method('atual')->willReturn($this->integracao);

        return new BancoCora(new ClienteCora($this->cora->cliente(), $this->cache, $this->cofre), $repo, $this->cofre);
    }
}
