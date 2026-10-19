<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Stripe;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\Stripe\AssinaturaDoAvisoStripe;
use App\Contribua\Gateway\Stripe\ClienteStripe;
use App\Contribua\Gateway\Stripe\GatewayStripe;
use App\Contribua\Gateway\Stripe\TradutorStripe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O que o contrato comum não cobre: o que vai ao Stripe e os casos de borda da tradução e do aviso. */
#[CoversClass(GatewayStripe::class)]
#[CoversClass(ClienteStripe::class)]
#[CoversClass(TradutorStripe::class)]
#[CoversClass(AssinaturaDoAvisoStripe::class)]
final class GatewayStripeTest extends TestCase
{
    /** @var list<array{metodo: string, url: string, corpo: string, cabecalhos: list<string>}> */
    private array $pedidos = [];

    #[TestDox('Assinar: produto criado na primeira vez, cliente com o cartão padrão e assinatura em BRL com a referência')]
    public function testAssinarComToken(): void
    {
        $gateway = $this->gatewayQueGrava([
            RespostasDoStripe::naoExiste(),
            RespostasDoStripe::json(RespostasDoStripe::produto()),
            RespostasDoStripe::json(RespostasDoStripe::cliente()),
            RespostasDoStripe::json(RespostasDoStripe::assinatura('active')),
        ]);

        $situacao = $gateway->assinarComToken(self::pedido(), 'pm_1Teste');

        self::assertSame(SituacaoDaAssinatura::Ativa, $situacao->situacao);
        self::assertSame('BJ000007-essencial-42', $situacao->referencia);
        self::assertSame(1990, $situacao->valorCentavos);
        self::assertSame(['GET', 'POST', 'POST', 'POST'], array_column($this->pedidos, 'metodo'));
        self::assertStringEndsWith('/v1/products/bluejus_espaco_essencial', $this->pedidos[0]['url']);
        parse_str($this->pedidos[2]['corpo'], $cliente);
        self::assertSame('pm_1Teste', $cliente['invoice_settings']['default_payment_method']);
        parse_str($this->pedidos[3]['corpo'], $assinatura);
        self::assertSame(['currency' => 'brl', 'product' => 'bluejus_espaco_essencial', 'unit_amount' => '1990', 'recurring' => ['interval' => 'month']], $assinatura['items'][0]['price_data']);
        self::assertSame('BJ000007-essencial-42', $assinatura['metadata']['referencia']);
        self::assertSame('allow_incomplete', $assinatura['payment_behavior']);
        self::assertContains('Stripe-Version: ' . ClienteStripe::VERSAO_DA_API, $this->pedidos[3]['cabecalhos']);
        self::assertNotEmpty(preg_grep('/^Idempotency-Key: assinatura-BJ000007-essencial-42-[0-9a-f]{16}$/', $this->pedidos[3]['cabecalhos']));
    }

    #[TestDox('Assinar recusa o que não é id de PaymentMethod, antes de chamar o Stripe')]
    public function testTokenInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gatewayQueGrava([])->assinarComToken(self::pedido(), '4242424242424242');
    }

    #[TestDox('Cartão recusado (402) vira FalhaNoGateway com o status e o código do Stripe')]
    public function testCartaoRecusado(): void
    {
        $gateway = $this->gatewayQueGrava([
            RespostasDoStripe::json(RespostasDoStripe::produto()),
            RespostasDoStripe::json(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']], 402),
        ]);

        try {
            $gateway->assinarComToken(self::pedido(), 'pm_1Teste');
            self::fail('deveria falhar');
        } catch (FalhaNoGateway $falha) {
            self::assertSame(402, $falha->getCode());
            self::assertStringContainsString('card_declined', $falha->getMessage());
        }
    }

    #[TestDox('Iniciar sem a chave pública configurada é falha do gateway, não formulário vazio')]
    public function testSemChavePublica(): void
    {
        $this->expectException(FalhaNoGateway::class);
        RespostasDoStripe::gateway([], '')->iniciar(self::pedido());
    }

    #[TestDox('Até quando está pago: inadimplente vale até o começo do período não pago; cancelada sem pagar, nada')]
    public function testPagaAte(): void
    {
        $inadimplente = RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::assinatura('past_due'))])->consultar('sub_1');
        $expirada = RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::assinatura('incomplete_expired', faturaPaga: 'void'))])->consultar('sub_1');
        $canceladaPaga = RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::assinatura('canceled'))])->consultar('sub_1');

        self::assertSame(RespostasDoStripe::INICIO_DO_PERIODO, $inadimplente->pagaAte?->getTimestamp());
        self::assertSame(SituacaoDaAssinatura::Cancelada, $expirada->situacao);
        self::assertNull($expirada->pagaAte);
        self::assertSame(RespostasDoStripe::FIM_DO_PERIODO, $canceladaPaga->pagaAte?->getTimestamp());
    }

    #[TestDox('Situação desconhecida do Stripe é falha, não um palpite')]
    public function testSituacaoDesconhecida(): void
    {
        $this->expectException(FalhaNoGateway::class);
        RespostasDoStripe::gateway([RespostasDoStripe::json(RespostasDoStripe::assinatura('situacao_nova'))])->consultar('sub_1');
    }

    #[TestDox('Cancelar o que já está cancelado não é erro; cancelar o que não existe é')]
    public function testCancelar(): void
    {
        $recusa = ['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']];
        $jaCancelada = $this->gatewayQueGrava([RespostasDoStripe::json($recusa, 400), RespostasDoStripe::json(RespostasDoStripe::assinatura('canceled'))]);

        $jaCancelada->cancelar('sub_1MowQVLkdIwHu7ixeRlqHVzs');

        self::assertSame(['DELETE', 'GET'], array_column($this->pedidos, 'metodo'));
        $this->expectException(FalhaNoGateway::class);
        RespostasDoStripe::gateway([RespostasDoStripe::json($recusa, 404), RespostasDoStripe::json($recusa, 404)])->cancelar('sub_inexistente');
    }

    #[TestDox('Aviso com carimbo velho (reenvio) é recusado mesmo com a assinatura certa')]
    public function testAvisoAntigo(): void
    {
        $corpo = RespostasDoStripe::evento('invoice.paid', RespostasDoStripe::faturaPaga());

        $this->expectException(AvisoNaoAutenticado::class);
        RespostasDoStripe::gateway([])->interpretarAviso(['stripe-signature' => RespostasDoStripe::assinar($corpo, RespostasDoStripe::AGORA - 301)], $corpo);
    }

    #[TestDox('Durante a troca do segredo chegam duas assinaturas v1: basta uma conferir')]
    public function testDuasAssinaturas(): void
    {
        $corpo = RespostasDoStripe::evento('customer.subscription.deleted', RespostasDoStripe::assinatura('canceled'));
        $velha = explode(',', RespostasDoStripe::assinar($corpo, segredo: 'whsec_antigo'))[1];

        $aviso = RespostasDoStripe::gateway([])->interpretarAviso(['stripe-signature' => RespostasDoStripe::assinar($corpo) . ',' . $velha . ',v0=ignorado'], $corpo);

        self::assertSame('sub_1MowQVLkdIwHu7ixeRlqHVzs', $aviso?->idDaAssinatura);
        self::assertSame('customer.subscription.deleted', $aviso?->tipo);
    }

    #[TestDox('Evento que o Contribua não usa, autêntico, volta null')]
    public function testEventoSemUso(): void
    {
        $corpo = RespostasDoStripe::evento('charge.refunded', ['id' => 'ch_1', 'object' => 'charge']);

        self::assertNull(RespostasDoStripe::gateway([])->interpretarAviso(['stripe-signature' => RespostasDoStripe::assinar($corpo)], $corpo));
    }

    /** @param list<MockResponse> $respostas */
    private function gatewayQueGrava(array $respostas): GatewayStripe
    {
        return RespostasDoStripe::gateway(function (string $metodo, string $url, array $opcoes) use (&$respostas): MockResponse {
            $this->pedidos[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => (string) ($opcoes['body'] ?? ''), 'cabecalhos' => $opcoes['headers'] ?? []];

            return array_shift($respostas) ?? self::fail('chamada a mais ao Stripe: ' . $metodo . ' ' . $url);
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
