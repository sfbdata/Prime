<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Pagarme;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\Pagarme\AssinaturaDoAvisoPagarme;
use App\Contribua\Gateway\Pagarme\ChavePublicaRsa;
use App\Contribua\Gateway\Pagarme\ClientePagarme;
use App\Contribua\Gateway\Pagarme\GatewayPagarme;
use App\Contribua\Gateway\Pagarme\TradutorPagarme;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O que o contrato comum não cobre: o que vai ao Pagar.me e os casos de borda da tradução e do aviso. */
#[CoversClass(GatewayPagarme::class)]
#[CoversClass(ClientePagarme::class)]
#[CoversClass(TradutorPagarme::class)]
#[CoversClass(AssinaturaDoAvisoPagarme::class)]
#[CoversClass(ChavePublicaRsa::class)]
final class GatewayPagarmeTest extends TestCase
{
    /** @var list<array{metodo: string, url: string, corpo: string, cabecalhos: list<string>}> */
    private array $pedidos = [];

    #[TestDox('Assinar: assinatura avulsa mensal pré-paga com o token, o cliente PJ, o preço em centavos e a referência')]
    public function testAssinarComToken(): void
    {
        $gateway = $this->gatewayQueGrava([RespostasDoPagarme::json(RespostasDoPagarme::assinatura()), RespostasDoPagarme::faturas([RespostasDoPagarme::fatura('paid')])]);

        $situacao = $gateway->assinarComToken(self::pedido(), 'token_zVw7rqvHEPH8XlbE');

        self::assertSame(SituacaoDaAssinatura::Ativa, $situacao->situacao);
        self::assertSame('BJ000007-essencial-42', $situacao->referencia);
        self::assertSame(1990, $situacao->valorCentavos);
        self::assertSame('https://api.pagar.me/core/v5/subscriptions', $this->pedidos[0]['url']);
        $corpo = json_decode($this->pedidos[0]['corpo'], true);
        self::assertSame('token_zVw7rqvHEPH8XlbE', $corpo['card_token']);
        self::assertSame(['month', 1, 'prepaid', 'BRL'], [$corpo['interval'], $corpo['interval_count'], $corpo['billing_type'], $corpo['currency']]);
        self::assertSame(['CNPJ', 'company'], [$corpo['customer']['document_type'], $corpo['customer']['type']]);
        self::assertSame(1990, $corpo['items'][0]['pricing_scheme']['price']);
        self::assertSame('BJ000007-essencial-42', $corpo['code']);
        self::assertContains('Authorization: Basic ' . base64_encode('sk_test_contribua:'), $this->pedidos[0]['cabecalhos']);
        self::assertStringContainsString('subscription_id=' . RespostasDoPagarme::ID, $this->pedidos[1]['url']);
    }

    #[TestDox('Assinar recusa o que não é token do Pagar.me, antes de chamar a API')]
    public function testTokenInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gatewayQueGrava([])->assinarComToken(self::pedido(), '4000000000000010');
    }

    #[TestDox('Recusa da API (422) vira FalhaNoGateway com o status e os campos')]
    public function testRecusa(): void
    {
        $gateway = RespostasDoPagarme::gateway([RespostasDoPagarme::json(['message' => 'The request is invalid.', 'errors' => ['subscription.customer.phones' => ['At least one customer phone is required.']]], 422)]);

        try {
            $gateway->assinarComToken(self::pedido(), 'token_zVw7rqvHEPH8XlbE');
            self::fail('deveria falhar');
        } catch (FalhaNoGateway $falha) {
            self::assertSame(422, $falha->getCode());
            self::assertStringContainsString('phone', $falha->getMessage());
        }
    }

    #[TestDox('Até quando está pago: o fim do ciclo da última fatura paga, em qualquer ordem da listagem')]
    public function testPagaAte(): void
    {
        $faturas = [RespostasDoPagarme::fatura('paid', '2026-09-19T00:00:00Z', '2026-10-18T23:59:59Z'), RespostasDoPagarme::fatura('pending'), RespostasDoPagarme::fatura('paid', '2026-08-19T00:00:00Z', '2026-09-18T23:59:59Z')];

        $renovando = $this->consultar('active', $faturas);
        $cancelada = $this->consultar('canceled', $faturas);
        $semPagar = $this->consultar('canceled', [RespostasDoPagarme::fatura('failed')]);

        self::assertSame(SituacaoDaAssinatura::Ativa, $renovando->situacao, 'a renovação ainda pendente não tira o mês pago');
        self::assertEquals(new \DateTimeImmutable('2026-10-18T23:59:59Z'), $renovando->pagaAte);
        self::assertEquals(new \DateTimeImmutable('2026-10-18T23:59:59Z'), $cancelada->pagaAte);
        self::assertNull($semPagar->pagaAte);
        self::assertSame('cus_017228NmffGbA3d4', $renovando->idDoCliente);
    }

    #[TestDox('Assinatura criada e faturas fora do ar: volta Aguardando com o id, não falha (evita assinar duas vezes)')]
    public function testFaturasForaDoArDepoisDeCriar(): void
    {
        $gateway = RespostasDoPagarme::gateway([RespostasDoPagarme::json(RespostasDoPagarme::assinatura()), new MockResponse('', ['error' => 'fora do ar'])]);

        $situacao = $gateway->assinarComToken(self::pedido(), 'token_zVw7rqvHEPH8XlbE');

        self::assertSame(RespostasDoPagarme::ID, $situacao->idDaAssinatura);
        self::assertSame(SituacaoDaAssinatura::Aguardando, $situacao->situacao);
        self::assertSame(1990, $situacao->valorCentavos);
    }

    #[TestDox('Primeira cobrança recusada é Aguardando; estorno contestado cancela sem mês pago')]
    public function testPrimeiraRecusaEEstorno(): void
    {
        $recusada = $this->consultar('active', [RespostasDoPagarme::fatura('failed')]);
        $estornada = $this->consultar('chargedback', [RespostasDoPagarme::fatura('paid')]);

        self::assertSame(SituacaoDaAssinatura::Aguardando, $recusada->situacao);
        self::assertSame(SituacaoDaAssinatura::Cancelada, $estornada->situacao);
        self::assertNull($estornada->pagaAte);
    }

    #[TestDox('Fatura paga sem ciclo: um mês depois da cobrança, sem pular mês')]
    public function testPagaSemCiclo(): void
    {
        $paga = RespostasDoPagarme::fatura('paid', '2027-01-31T00:00:00Z');
        unset($paga['cycle']);

        self::assertSame('2027-02-28', $this->consultar('active', [$paga])->pagaAte?->format('Y-m-d'));
    }

    #[TestDox('Aviso forjado com kid inventado: busca o JWKS de novo no máximo uma vez a cada 5 minutos')]
    public function testKidInventado(): void
    {
        $corpo = RespostasDoPagarme::aviso('invoice.paid', RespostasDoPagarme::fatura('paid'));
        $gateway = RespostasDoPagarme::gateway([], [RespostasDoPagarme::jwks(), RespostasDoPagarme::jwks()]);
        $forjado = ['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($corpo, 'kid-inventado', RespostasDoPagarme::novaChave())];

        foreach ([1, 2, 3] as $vez) {
            try {
                $gateway->interpretarAviso($forjado, $corpo);
                self::fail('deveria recusar');
            } catch (AvisoNaoAutenticado) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('Situação desconhecida do Pagar.me é falha, não um palpite')]
    public function testSituacaoDesconhecida(): void
    {
        $this->expectException(FalhaNoGateway::class);
        $this->consultar('situacao_nova', []);
    }

    #[TestDox('Cancelar cancela as faturas pendentes; já cancelada não é erro; inexistente é')]
    public function testCancelar(): void
    {
        $gateway = $this->gatewayQueGrava([
            RespostasDoPagarme::json(RespostasDoPagarme::assinatura('canceled')),
            RespostasDoPagarme::json(['message' => 'This subscription is canceled.'], 412),
            RespostasDoPagarme::json(RespostasDoPagarme::assinatura('canceled')),
            RespostasDoPagarme::faturas([]),
        ]);

        $gateway->cancelar(RespostasDoPagarme::ID);
        $gateway->cancelar(RespostasDoPagarme::ID);

        self::assertSame(['DELETE', 'DELETE', 'GET', 'GET'], array_column($this->pedidos, 'metodo'));
        self::assertSame(['cancel_pending_invoices' => true], json_decode($this->pedidos[0]['corpo'], true));
        $this->expectException(FalhaNoGateway::class);
        RespostasDoPagarme::gateway([RespostasDoPagarme::json(['message' => 'Subscription not found.'], 404), RespostasDoPagarme::json(['message' => 'Subscription not found.'], 404)])->cancelar('sub_inexistente');
    }

    #[TestDox('Aviso de cobrança: a assinatura vem de data.invoice; evento sem uso volta null')]
    public function testAvisoDeCobranca(): void
    {
        $cobranca = RespostasDoPagarme::aviso('charge.paid', ['id' => 'ch_1', 'status' => 'paid', 'invoice' => ['id' => 'in_1', 'subscription_id' => RespostasDoPagarme::ID]]);
        $pedido = RespostasDoPagarme::aviso('order.paid', ['id' => 'or_1']);
        $gateway = RespostasDoPagarme::gateway([]);

        self::assertSame(RespostasDoPagarme::ID, $gateway->interpretarAviso(['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($cobranca)], $cobranca)?->idDaAssinatura);
        self::assertNull($gateway->interpretarAviso(['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($pedido)], $pedido));
    }

    #[TestDox('Chave nova no Pagar.me: o cache velho não confere, busca o JWKS de novo e aceita')]
    public function testTrocaDeChave(): void
    {
        $nova = RespostasDoPagarme::novaChave();
        $corpo = RespostasDoPagarme::aviso('subscription.canceled', RespostasDoPagarme::assinatura('canceled'));
        $gateway = RespostasDoPagarme::gateway([], [RespostasDoPagarme::jwks(), RespostasDoPagarme::jwks($nova, 'chave-2')]);
        $velho = RespostasDoPagarme::aviso('subscription.created', RespostasDoPagarme::assinatura());
        $gateway->interpretarAviso(['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($velho)], $velho);

        $aviso = $gateway->interpretarAviso(['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($corpo, 'chave-2', $nova)], $corpo);

        self::assertSame('BJ000007-essencial-42', $aviso?->referencia);
    }

    #[TestDox('Aviso sem cabeçalho ou com algoritmo diferente de RS256 é recusado sem buscar chave')]
    public function testAvisoMalFormado(): void
    {
        $corpo = RespostasDoPagarme::aviso('invoice.paid', RespostasDoPagarme::fatura('paid'));
        $gateway = RespostasDoPagarme::gateway([], []);

        foreach (['', 'alg=HS256; kid=' . RespostasDoPagarme::KID . '; signature=abc'] as $cabecalho) {
            try {
                $gateway->interpretarAviso(['x-webhook-asymmetric-signature' => $cabecalho], $corpo);
                self::fail('deveria recusar');
            } catch (AvisoNaoAutenticado) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('JWKS fora do ar: FalhaNoGateway (o Pagar.me reenvia), não "aviso falso"')]
    public function testJwksForaDoAr(): void
    {
        $corpo = RespostasDoPagarme::aviso('invoice.paid', RespostasDoPagarme::fatura('paid'));

        $this->expectException(FalhaNoGateway::class);
        RespostasDoPagarme::gateway([], [new MockResponse('', ['error' => 'fora do ar'])])->interpretarAviso(['x-webhook-asymmetric-signature' => RespostasDoPagarme::assinar($corpo)], $corpo);
    }

    /** @param list<array<string, mixed>> $faturas */
    private function consultar(string $status, array $faturas): \App\Contribua\Gateway\SituacaoNoGateway
    {
        return RespostasDoPagarme::gateway([RespostasDoPagarme::json(RespostasDoPagarme::assinatura($status)), RespostasDoPagarme::faturas($faturas)])->consultar(RespostasDoPagarme::ID);
    }

    /** @param list<MockResponse> $respostas */
    private function gatewayQueGrava(array $respostas): GatewayPagarme
    {
        return RespostasDoPagarme::gateway(function (string $metodo, string $url, array $opcoes) use (&$respostas): MockResponse {
            $this->pedidos[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => (string) ($opcoes['body'] ?? ''), 'cabecalhos' => $opcoes['headers'] ?? []];

            return array_shift($respostas) ?? self::fail('chamada a mais ao Pagar.me: ' . $metodo . ' ' . $url);
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
