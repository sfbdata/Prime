<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\MercadoPago;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\MercadoPago\AssinaturaDoAvisoMercadoPago;
use App\Contribua\Gateway\MercadoPago\ClienteMercadoPago;
use App\Contribua\Gateway\MercadoPago\GatewayMercadoPago;
use App\Contribua\Gateway\MercadoPago\TradutorMercadoPago;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O que o contrato comum não cobre: o que vai ao Mercado Pago e os casos de borda da tradução e do aviso. */
#[CoversClass(GatewayMercadoPago::class)]
#[CoversClass(ClienteMercadoPago::class)]
#[CoversClass(TradutorMercadoPago::class)]
#[CoversClass(AssinaturaDoAvisoMercadoPago::class)]
final class GatewayMercadoPagoTest extends TestCase
{
    /** @var list<array{metodo: string, url: string, corpo: string, cabecalhos: list<string>}> */
    private array $pedidos = [];

    #[TestDox('Assinar: preapproval autorizado com o token, o valor em reais, o e-mail do pagador e a referência')]
    public function testAssinarComToken(): void
    {
        $gateway = $this->gatewayQueGrava([RespostasDoMercadoPago::json(RespostasDoMercadoPago::assinatura('authorized', null), 201)]);

        $situacao = $gateway->assinarComToken(self::pedido(), 'e3ed6f098462036dd2cbabe314b9de2a');

        self::assertSame(SituacaoDaAssinatura::Aguardando, $situacao->situacao, 'a primeira cobrança sai até 1 h depois');
        self::assertSame('BJ000007-essencial-42', $situacao->referencia);
        self::assertSame(1990, $situacao->valorCentavos);
        self::assertSame('POST', $this->pedidos[0]['metodo']);
        self::assertSame('https://api.mercadopago.com/preapproval', $this->pedidos[0]['url']);
        $corpo = json_decode($this->pedidos[0]['corpo'], true);
        self::assertSame('e3ed6f098462036dd2cbabe314b9de2a', $corpo['card_token_id']);
        self::assertSame('authorized', $corpo['status']);
        self::assertSame('financeiro@escritorio.test', $corpo['payer_email']);
        self::assertSame(['frequency' => 1, 'frequency_type' => 'months', 'transaction_amount' => 19.9, 'currency_id' => 'BRL'], $corpo['auto_recurring']);
        self::assertArrayNotHasKey('start_date', $corpo['auto_recurring']);
        self::assertNotEmpty(preg_grep('/^X-Idempotency-Key: assinatura-BJ000007-essencial-42-[0-9a-f]{16}$/', $this->pedidos[0]['cabecalhos']));
    }

    #[TestDox('Assinar recusa o que não parece token, antes de chamar o Mercado Pago')]
    public function testTokenInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gatewayQueGrava([])->assinarComToken(self::pedido(), '5480 8328 0103 3311');
    }

    #[TestDox('Token recusado (400) vira FalhaNoGateway com o status e a causa')]
    public function testTokenRecusado(): void
    {
        $gateway = RespostasDoMercadoPago::gateway([RespostasDoMercadoPago::json(['message' => 'Card Token not found', 'error' => 'bad_request', 'status' => 400, 'cause' => [['code' => 2006, 'description' => 'Card Token not found']]], 400)]);

        try {
            $gateway->assinarComToken(self::pedido(), 'e3ed6f098462036dd2cbabe314b9de2a');
            self::fail('deveria falhar');
        } catch (FalhaNoGateway $falha) {
            self::assertSame(400, $falha->getCode());
            self::assertStringContainsString('2006', $falha->getMessage());
        }
    }

    #[TestDox('Até quando está pago: ativa até a próxima cobrança; inadimplente e cancelada, um mês depois da última')]
    public function testPagaAte(): void
    {
        $ativa = $this->consultar(RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('green', 1)));
        $tentando = $this->consultar(RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('yellow', 2)));
        $cancelada = $this->consultar(RespostasDoMercadoPago::assinatura('cancelled', RespostasDoMercadoPago::resumo('green', 2)));
        $semPagar = $this->consultar(RespostasDoMercadoPago::assinatura('canceled', RespostasDoMercadoPago::resumo('green', 0)));

        self::assertEquals(new \DateTimeImmutable(RespostasDoMercadoPago::PROXIMA_COBRANCA), $ativa->pagaAte);
        self::assertSame(SituacaoDaAssinatura::Inadimplente, $tentando->situacao);
        self::assertEquals((new \DateTimeImmutable(RespostasDoMercadoPago::ULTIMA_COBRANCA))->modify('+1 months'), $tentando->pagaAte);
        self::assertSame(SituacaoDaAssinatura::Cancelada, $cancelada->situacao, 'as duas grafias de cancelada');
        self::assertNull($semPagar->pagaAte);
        self::assertSame('123123123', $ativa->idDoCliente);
    }

    #[TestDox('Primeira cobrança recusada (semáforo vermelho sem cobrança feita) é Aguardando, não inadimplência')]
    public function testPrimeiraCobrancaRecusada(): void
    {
        $recusada = $this->consultar(RespostasDoMercadoPago::assinatura('authorized', RespostasDoMercadoPago::resumo('red', 0)));
        $pausadaSemPagar = $this->consultar(RespostasDoMercadoPago::assinatura('paused', RespostasDoMercadoPago::resumo('green', 0)));

        self::assertSame(SituacaoDaAssinatura::Aguardando, $recusada->situacao);
        self::assertNull($recusada->pagaAte);
        self::assertSame(SituacaoDaAssinatura::Aguardando, $pausadaSemPagar->situacao);
    }

    #[TestDox('Mês pago sem pular mês: cobrança em 31/01 vale até 28/02; ativa sem próxima cobrança usa a última')]
    public function testSomaDeMes(): void
    {
        $resumo = ['last_charged_date' => '2027-01-31T10:00:00.000-03:00', 'charged_quantity' => 1] + RespostasDoMercadoPago::resumo('red', 1);
        $semProxima = RespostasDoMercadoPago::assinatura('authorized', ['last_charged_date' => '2027-01-31T10:00:00.000-03:00'] + RespostasDoMercadoPago::resumo('green', 1));
        unset($semProxima['next_payment_date']);

        $inadimplente = $this->consultar(RespostasDoMercadoPago::assinatura('authorized', $resumo));
        $ativa = $this->consultar($semProxima);

        self::assertSame('2027-02-28', $inadimplente->pagaAte?->format('Y-m-d'));
        self::assertSame('2027-02-28', $ativa->pagaAte?->format('Y-m-d'));
    }

    #[TestDox('O id assinado é o da query da URL (x-query-data.id), não o do corpo')]
    public function testIdDaQuery(): void
    {
        $corpoTrocado = RespostasDoMercadoPago::aviso('subscription_preapproval', 'outro-recurso');
        $cabecalhos = RespostasDoMercadoPago::cabecalhos(RespostasDoMercadoPago::ID) + ['x-query-data.id' => RespostasDoMercadoPago::ID];

        $aviso = RespostasDoMercadoPago::gateway([])->interpretarAviso($cabecalhos, $corpoTrocado);

        self::assertSame(RespostasDoMercadoPago::ID, $aviso?->idDaAssinatura);
    }

    #[TestDox('Situação desconhecida do Mercado Pago é falha, não um palpite')]
    public function testSituacaoDesconhecida(): void
    {
        $this->expectException(FalhaNoGateway::class);
        $this->consultar(RespostasDoMercadoPago::assinatura('finished', null));
    }

    #[TestDox('Cancelar envia status canceled; já cancelada não é erro; inexistente é')]
    public function testCancelar(): void
    {
        $recusa = ['message' => 'Not valid action, the resource is in a state that does not allow this operation', 'status' => 400, 'cause' => [['code' => 3005]]];
        $gateway = $this->gatewayQueGrava([
            RespostasDoMercadoPago::json(RespostasDoMercadoPago::assinatura('canceled', null)),
            RespostasDoMercadoPago::json($recusa, 400),
            RespostasDoMercadoPago::json(RespostasDoMercadoPago::assinatura('canceled', RespostasDoMercadoPago::resumo('green', 1))),
        ]);

        $gateway->cancelar(RespostasDoMercadoPago::ID);
        $gateway->cancelar(RespostasDoMercadoPago::ID);

        self::assertSame(['PUT', 'PUT', 'GET'], array_column($this->pedidos, 'metodo'));
        self::assertSame(['status' => 'canceled'], json_decode($this->pedidos[0]['corpo'], true));
        $this->expectException(FalhaNoGateway::class);
        RespostasDoMercadoPago::gateway([RespostasDoMercadoPago::json(['message' => 'not found'], 404), RespostasDoMercadoPago::json(['message' => 'not found'], 404)])->cancelar('inexistente');
    }

    #[TestDox('Aviso de fatura: a assinatura vem da consulta da fatura')]
    public function testAvisoDeFatura(): void
    {
        $gateway = $this->gatewayQueGrava([RespostasDoMercadoPago::json(['id' => 6114264375, 'type' => 'scheduled', 'preapproval_id' => RespostasDoMercadoPago::ID, 'status' => 'processed'])]);

        $aviso = $gateway->interpretarAviso(RespostasDoMercadoPago::cabecalhos('6114264375'), RespostasDoMercadoPago::aviso('subscription_authorized_payment', '6114264375', 777));

        self::assertSame(RespostasDoMercadoPago::ID, $aviso?->idDaAssinatura);
        self::assertSame('777', $aviso?->idDoEvento);
        self::assertStringEndsWith('/authorized_payments/6114264375', $this->pedidos[0]['url']);
    }

    #[TestDox('Aviso de pagamento avulso, autêntico, volta null; sem x-signature é recusado')]
    public function testAvisoSemUsoESemAssinatura(): void
    {
        $corpo = RespostasDoMercadoPago::aviso('payment', '999999999');

        self::assertNull(RespostasDoMercadoPago::gateway([])->interpretarAviso(RespostasDoMercadoPago::cabecalhos('999999999'), $corpo));
        $this->expectException(AvisoNaoAutenticado::class);
        RespostasDoMercadoPago::gateway([])->interpretarAviso(['x-request-id' => 'abc'], $corpo);
    }

    #[TestDox('Manifesto do x-signature: id em minúsculas e parte ausente fora (exemplo da doc)')]
    public function testManifesto(): void
    {
        self::assertSame('id:ord01jq4s4ky8hwq6na5pxb65b3d3;request-id:abc;ts:1704908010;', AssinaturaDoAvisoMercadoPago::manifesto('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3', 'abc', '1704908010'));
        self::assertSame('id:123;ts:1704908010;', AssinaturaDoAvisoMercadoPago::manifesto('123', '', '1704908010'));
    }

    /** @param array<string, mixed> $assinatura */
    private function consultar(array $assinatura): \App\Contribua\Gateway\SituacaoNoGateway
    {
        return RespostasDoMercadoPago::gateway([RespostasDoMercadoPago::json($assinatura)])->consultar(RespostasDoMercadoPago::ID);
    }

    /** @param list<MockResponse> $respostas */
    private function gatewayQueGrava(array $respostas): GatewayMercadoPago
    {
        return RespostasDoMercadoPago::gateway(function (string $metodo, string $url, array $opcoes) use (&$respostas): MockResponse {
            $this->pedidos[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => (string) ($opcoes['body'] ?? ''), 'cabecalhos' => $opcoes['headers'] ?? []];

            return array_shift($respostas) ?? self::fail('chamada a mais ao Mercado Pago: ' . $metodo . ' ' . $url);
        });
    }

    private static function pedido(): PedidoDeCheckout
    {
        return new PedidoDeCheckout(
            'BJ000007-essencial-42',
            7,
            'essencial',
            'Essencial',
            1990,
            new Pagador('Escritório de Teste', '12345678000195', 'financeiro@escritorio.test'),
            'https://bluejus.com.br/contribua?assinatura=ok',
            'https://bluejus.com.br/contribua#espaco',
        );
    }
}
