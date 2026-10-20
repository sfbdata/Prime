<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Banco;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\BancoCora;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeBoleto;
use App\Contribua\Entity\IntegracaoBancaria;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Boleto de apoio pela Cora (chave `bol`): o corpo exato do `POST /v2/invoices/` e a leitura da resposta. */
#[CoversClass(BancoCora::class)]
#[CoversClass(PedidoDeBoleto::class)]
final class BoletoDaCoraTest extends TestCase
{
    private CoraFalsa $cora;
    private CofreDeCertificado $cofre;
    private IntegracaoBancaria $integracao;

    protected function setUp(): void
    {
        $this->cora = new CoraFalsa();
        $this->cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        $this->integracao = new IntegracaoBancaria();
        $par = ParDeCertificado::novo();
        $this->integracao->guardarCertificado($this->cofre->cifrar($par['certificado']), $this->cofre->cifrar($par['chave']), ['cert.pem', 'chave.key'], new User());
        $this->integracao->configurar('sandbox', 'cliente-1', [], new User());
    }

    #[TestDox('Corpo do boleto: BANK_SLIP e PIX, cliente com nome (60), e-mail e documento, serviço de até 100 caracteres, vencimento, sem multa e sem juros, com o Idempotency-Key')]
    public function testCorpoExato(): void
    {
        $this->sut()->emitirBoleto($this->pedido(2000, '11222333000181', str_repeat('Escritório Longo ', 6)));

        $pedido = $this->cora->pedidos[1];
        self::assertSame('POST', $pedido['metodo']);
        self::assertSame('https://matls-clients.api.stage.cora.com.br/v2/invoices/', $pedido['url']);
        self::assertSame('5f0e4c1a-7b2d-4e8f-9a3c-1d2e3f4a5b6c', $this->cora->cabecalho($pedido['opcoes'], 'idempotency-key'));
        self::assertSame([
            'code' => 'BJ000001-Babc123',
            'customer' => [
                'name' => mb_substr(str_repeat('Escritório Longo ', 6), 0, 60),
                'email' => 'quem@escritorio.com.br',
                'document' => ['identity' => '11222333000181', 'type' => 'CNPJ'],
            ],
            'services' => [['name' => 'Apoio ao BlueJus', 'description' => 'Contribuição voluntária, conta BJ000001-Babc123', 'amount' => 2000]],
            'payment_terms' => ['due_date' => '2030-03-20'],
            'payment_forms' => ['BANK_SLIP', 'PIX'],
        ], json_decode((string) $pedido['opcoes']['body'], true));
    }

    #[TestDox('CPF vai com o tipo CPF')]
    public function testCpf(): void
    {
        $this->sut()->emitirBoleto($this->pedido(2000, '52998224725'));

        $corpo = json_decode((string) $this->cora->pedidos[1]['opcoes']['body'], true);
        self::assertSame(['identity' => '52998224725', 'type' => 'CPF'], $corpo['customer']['document']);
    }

    #[TestDox('Resposta: id, linha digitável, PDF e o Pix do boleto; sem chave Pix na conta, o Pix vem nulo')]
    public function testResposta(): void
    {
        $com = $this->sut()->emitirBoleto($this->pedido(2000));
        $this->cora->boletoSemPix = true;
        $sem = $this->sut()->emitirBoleto($this->pedido(5000));

        self::assertStringStartsWith('inv_', $com->idNoBanco);
        self::assertSame('40390000007550000000000000000000189170000005000', $com->linhaDigitavel);
        self::assertStringStartsWith('https://web.stage.cora.com.br/boleto/', $com->url);
        self::assertStringStartsWith('000201', (string) $com->pixCopiaECola);
        self::assertNull($sem->pixCopiaECola);
    }

    #[TestDox('Abaixo de R$ 5,00 o adaptador recusa sem chamar a Cora')]
    public function testMinimo(): void
    {
        try {
            $this->sut()->emitirBoleto($this->pedido(499));
            self::fail('deveria recusar');
        } catch (\DomainException $e) {
            self::assertSame('O boleto tem valor mínimo de R$ 5,00.', $e->getMessage());
        }
        self::assertSame([], $this->cora->pedidos);
    }

    #[TestDox('Resposta sem linha digitável ou com PDF fora de https: falha do banco (não passageira)')]
    public function testRespostaRuim(): void
    {
        foreach ([['bank_slip' => ['digitable' => '', 'url' => 'https://x']], ['bank_slip' => ['digitable' => '4039', 'url' => 'javascript:alert(1)']], null] as $opcoes) {
            $http = new MockHttpClient([
                new MockResponse('{"access_token":"t"}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
                new MockResponse((string) json_encode(['id' => 'inv_1', 'payment_options' => $opcoes]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
            ]);
            try {
                $this->sut($http)->emitirBoleto($this->pedido(2000));
                self::fail('deveria falhar');
            } catch (FalhaNoBanco $e) {
                self::assertFalse($e->passageira);
            }
        }
    }

    #[TestDox('Cora fora do ar: falha passageira, com o status')]
    public function testCoraFora(): void
    {
        $this->cora->statusDaCriacao = 500;
        $this->expectException(FalhaNoBanco::class);
        $this->expectExceptionMessage('A Cora respondeu 500');

        $this->sut()->emitirBoleto($this->pedido(2000));
    }

    private function pedido(int $centavos, string $documento = '11222333000181', string $nome = 'Escritório Teste'): PedidoDeBoleto
    {
        return new PedidoDeBoleto('BJ000001-Babc123', '5f0e4c1a-7b2d-4e8f-9a3c-1d2e3f4a5b6c', $nome, $documento, 'quem@escritorio.com.br', $centavos, new \DateTimeImmutable('2030-03-20'));
    }

    private function sut(?MockHttpClient $http = null): BancoCora
    {
        $repo = $this->createStub(IntegracaoBancariaRepository::class);
        $repo->method('atual')->willReturn($this->integracao);

        return new BancoCora(new ClienteCora($http ?? $this->cora->cliente(), new ArrayAdapter(), $this->cofre), $repo, $this->cofre);
    }
}
