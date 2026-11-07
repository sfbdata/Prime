<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Asaas;

use App\Contribua\Gateway\Asaas\GatewayAsaas;
use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\EmiteNotaFiscalDaAssinatura;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\Pagador;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** O que é só do Asaas: checkout sem cliente, corpo do Checkout, vínculo pelo checkout, token do webhook, 404 e nota fiscal. */
#[CoversClass(GatewayAsaas::class)]
final class GatewayAsaasTest extends TestCase
{
    private const ID = ApiAsaasFalsa::ID_DA_ASSINATURA;

    #[TestDox('O checkout vai sem cliente: nenhuma chamada a /customers e o POST /checkouts sem customer nem customerData')]
    public function testCheckoutSemCliente(): void
    {
        $api = self::apiQueIniciaComSucesso();

        $api->gateway()->iniciar(self::pedido());

        self::assertSame(['POST /checkouts'], $api->rotasChamadas());
        self::assertContains('access_token: ' . ApiAsaasFalsa::CHAVE, $api->chamadas[0]['cabecalhos']);
        $corpo = $api->corpoEnviado('POST /checkouts');
        self::assertArrayNotHasKey('customer', $corpo, 'cliente pré-criado faz o Asaas pedir telefone e endereço (HTTP 400)');
        self::assertArrayNotHasKey('customerData', $corpo, 'só nome, CPF/CNPJ e e-mail também dá 400');
        self::assertStringNotContainsString('12345678000195', (string) json_encode($corpo), 'o CPF/CNPJ do titular não vai ao Asaas');
    }

    #[TestDox('O POST /checkouts leva cartão, recorrência mensal, valor em reais, retorno e a referência do pedido')]
    public function testCorpoDoCheckoutCampoACampo(): void
    {
        $api = self::apiQueIniciaComSucesso();

        $api->gateway()->iniciar(self::pedido());

        $corpo = $api->corpoEnviado('POST /checkouts');
        self::assertSame(['CREDIT_CARD'], $corpo['billingTypes']);
        self::assertSame(['RECURRENT'], $corpo['chargeTypes']);
        self::assertSame(60, $corpo['minutesToExpire']);
        self::assertSame([
            'successUrl' => 'https://bluejus.com.br/contribua?assinatura=ok',
            'cancelUrl' => 'https://bluejus.com.br/contribua#espaco',
            'expiredUrl' => 'https://bluejus.com.br/contribua#espaco',
        ], $corpo['callback']);
        self::assertSame([[
            'name' => 'BlueJus Essencial',
            'description' => 'Espaço do plano Essencial',
            'quantity' => 1,
            'value' => 19.9,
        ]], $corpo['items']);
        self::assertSame(['cycle' => 'MONTHLY', 'nextDueDate' => '2026-10-19'], $corpo['subscription']);
        self::assertSame('BJ000007-essencial-42', $corpo['externalReference']);
        self::assertSame(['billingTypes', 'chargeTypes', 'minutesToExpire', 'callback', 'items', 'subscription', 'externalReference'], array_keys($corpo));
    }

    #[TestDox('O link do checkout vem do JSON do Asaas quando ele traz "link"')]
    public function testLinkDoJson(): void
    {
        $inicio = self::apiQueIniciaComSucesso()->gateway()->iniciar(self::pedido());

        self::assertSame(InicioDoCheckout::REDIRECIONAR, $inicio->modo);
        self::assertSame('https://sandbox.asaas.com/checkoutSession/show?id=' . ApiAsaasFalsa::ID_DO_CHECKOUT, $inicio->urlDeRedirecionamento);
        self::assertSame(ApiAsaasFalsa::ID_DO_CHECKOUT, $inicio->idNoGateway);
    }

    #[TestDox('Sem "link" no JSON, o link é montado pelo id no host do mesmo ambiente da API')]
    public function testLinkMontadoPeloId(): void
    {
        $api = new ApiAsaasFalsa(['POST /checkouts' => [200, ApiAsaasFalsa::checkoutCriado(false)]]);

        $inicio = $api->gateway()->iniciar(self::pedido());

        self::assertSame('https://sandbox.asaas.com/checkoutSession/show?id=' . ApiAsaasFalsa::ID_DO_CHECKOUT, $inicio->urlDeRedirecionamento);
    }

    #[TestDox('Ativa: pagaAte é o vencimento da última cobrança paga + 1 mês, com o checkout de origem, cliente e valor')]
    public function testConsultarAtivaComDetalhes(): void
    {
        $api = self::apiDaAssinatura('ACTIVE', [
            ApiAsaasFalsa::cobranca('pay_2', 'CONFIRMED', '2026-10-19'),
            ApiAsaasFalsa::cobranca('pay_3', 'PENDING', '2026-11-19'),
            ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19'),
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(SituacaoDaAssinatura::Ativa, $situacao->situacao);
        self::assertSame('2026-11-19', $situacao->pagaAte?->format('Y-m-d'));
        self::assertSame(ApiAsaasFalsa::ID_DO_CHECKOUT, $situacao->idDoCheckout, 'o checkoutSession liga a assinatura ao pedido');
        self::assertNull($situacao->referencia, 'sem externalReference na assinatura, sem referência');
        self::assertSame(ApiAsaasFalsa::ID_DO_CLIENTE, $situacao->idDoCliente);
        self::assertSame(1990, $situacao->valorCentavos);
    }

    #[TestDox('Consultar não lê o cliente: a referência vem só do externalReference da própria assinatura')]
    public function testConsultarSemLerOCliente(): void
    {
        $api = new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura('ACTIVE', false, 'BJ000007-essencial-42')],
            'GET /subscriptions/' . self::ID . '/payments' => [200, ApiAsaasFalsa::cobrancasDaAssinatura([ApiAsaasFalsa::cobranca('pay_1', 'CONFIRMED', '2026-10-19')])],
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame('BJ000007-essencial-42', $situacao->referencia);
        self::assertSame(['GET /subscriptions/' . self::ID, 'GET /subscriptions/' . self::ID . '/payments'], $api->rotasChamadas());
    }

    #[TestDox('Assinatura criada fora do checkout (sem checkoutSession): idDoCheckout null')]
    public function testConsultarSemCheckoutDeOrigem(): void
    {
        $api = new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura('ACTIVE', false, null, null)],
            'GET /subscriptions/' . self::ID . '/payments' => [200, ApiAsaasFalsa::cobrancasDaAssinatura([])],
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertNull($situacao->idDoCheckout);
        self::assertNull($situacao->referencia);
    }

    #[TestDox('Assinatura removida (deleted true) é Cancelada mesmo com status ACTIVE')]
    public function testConsultarRemovidaECancelada(): void
    {
        $api = new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura('ACTIVE', true)],
            'GET /subscriptions/' . self::ID . '/payments' => [200, ApiAsaasFalsa::cobrancasDaAssinatura([])],
        ]);

        self::assertSame(SituacaoDaAssinatura::Cancelada, $api->gateway()->consultar(self::ID)->situacao);
    }

    #[TestDox('Status 5xx do Asaas vira FalhaNoGateway com o status na mensagem')]
    public function testErroDoServidorViraFalha(): void
    {
        $api = new ApiAsaasFalsa(['GET /subscriptions/' . self::ID => [503, 'Service Unavailable']]);

        $this->expectException(FalhaNoGateway::class);
        $this->expectExceptionMessage('HTTP 503');
        $api->gateway()->consultar(self::ID);
    }

    #[TestDox('Erro 400 do Asaas leva só o código para o log, nunca a descrição com dado do cliente')]
    public function testErroDeValidacaoLevaSoOCodigo(): void
    {
        $api = new ApiAsaasFalsa([
            'POST /checkouts' => [400, ['errors' => [['code' => 'invalid_object', 'description' => 'O campo phone deve existir para o customer 12345678000195.']]]],
        ]);

        try {
            $api->gateway()->iniciar(self::pedido());
            self::fail('deveria lançar FalhaNoGateway');
        } catch (FalhaNoGateway $falha) {
            self::assertStringContainsString('invalid_object', $falha->getMessage());
            self::assertStringNotContainsString('12345678000195', $falha->getMessage());
        }
    }

    #[TestDox('Sem URL ou chave da API, o gateway responde FalhaNoGateway sem chamar nada')]
    public function testSemConfiguracao(): void
    {
        $gateway = new GatewayAsaas(new MockHttpClient(static function (): never {
            throw new \LogicException('não deveria chamar a API');
        }), new MockClock());

        $this->expectException(FalhaNoGateway::class);
        $gateway->consultar(self::ID);
    }

    #[TestDox('Webhook com o token errado é recusado')]
    public function testTokenDoWebhookErradoRecusado(): void
    {
        $this->expectException(AvisoNaoAutenticado::class);
        (new ApiAsaasFalsa())->gateway()->interpretarAviso(['asaas-access-token' => 'token-errado'], ApiAsaasFalsa::avisoDeAssinatura());
    }

    #[TestDox('Sem token configurado, nenhum webhook passa (nem com o cabeçalho vazio)')]
    public function testSemTokenConfiguradoRecusaTudo(): void
    {
        $this->expectException(AvisoNaoAutenticado::class);
        (new ApiAsaasFalsa())->gateway([], '')->interpretarAviso(['asaas-access-token' => ''], ApiAsaasFalsa::avisoDeAssinatura());
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    public static function avisos(): iterable
    {
        yield 'cobrança da assinatura confirmada' => [ApiAsaasFalsa::avisoDeCobranca('PAYMENT_CONFIRMED', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE), 'PAYMENT_CONFIRMED', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE];
        yield 'cobrança vencida' => [ApiAsaasFalsa::avisoDeCobranca('PAYMENT_OVERDUE', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE), 'PAYMENT_OVERDUE', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE];
        yield 'assinatura removida' => [ApiAsaasFalsa::avisoDeAssinatura('SUBSCRIPTION_DELETED'), 'SUBSCRIPTION_DELETED', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE];
        yield 'checkout pago com o cliente que o pagador cadastrou' => [ApiAsaasFalsa::avisoDeCheckout('CHECKOUT_PAID', ApiAsaasFalsa::ID_DO_CLIENTE), 'CHECKOUT_PAID', null, ApiAsaasFalsa::ID_DO_CLIENTE];
        yield 'checkout sem cliente (só a assinatura liga ao pedido)' => [ApiAsaasFalsa::avisoDeCheckout(), null, null, null];
        yield 'cobrança avulsa com cliente' => [ApiAsaasFalsa::avisoDeCobranca('PAYMENT_RECEIVED', null, ApiAsaasFalsa::ID_DO_CLIENTE), 'PAYMENT_RECEIVED', null, ApiAsaasFalsa::ID_DO_CLIENTE];
        yield 'cobrança avulsa sem assinatura nem cliente' => [ApiAsaasFalsa::avisoDeCobranca('PAYMENT_RECEIVED', null, null), null, null, null];
        yield 'evento que o Contribua não usa' => [ApiAsaasFalsa::avisoDeCobranca('PAYMENT_CREATED', self::ID, ApiAsaasFalsa::ID_DO_CLIENTE), null, null, null];
        yield 'corpo que não é JSON' => ['isto não é JSON', null, null, null];
        foreach (['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'PAYMENT_DELETED', 'PAYMENT_RESTORED', 'PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_REFUND_IN_PROGRESS'] as $evento) {
            yield 'reconsulta em ' . $evento => [ApiAsaasFalsa::avisoDeCobranca($evento, self::ID, ApiAsaasFalsa::ID_DO_CLIENTE), $evento, self::ID, ApiAsaasFalsa::ID_DO_CLIENTE];
        }
    }

    #[DataProvider('avisos')]
    #[TestDox('Webhook autêntico é traduzido conforme o evento')]
    public function testInterpretarAviso(string $corpo, ?string $tipo, ?string $idDaAssinatura, ?string $idDoCliente): void
    {
        $aviso = (new ApiAsaasFalsa())->gateway()->interpretarAviso(['asaas-access-token' => ApiAsaasFalsa::TOKEN_DO_WEBHOOK], $corpo);

        if ($tipo === null) {
            self::assertNull($aviso);

            return;
        }
        self::assertNotNull($aviso);
        self::assertSame($tipo, $aviso->tipo);
        self::assertSame($idDaAssinatura, $aviso->idDaAssinatura);
        self::assertNull($aviso->referencia);
        self::assertSame($idDoCliente, $aviso->idDoCliente);
    }

    #[TestDox('Cancelar faz DELETE /subscriptions/{id}')]
    public function testCancelar(): void
    {
        $api = new ApiAsaasFalsa(['DELETE /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinaturaRemovida()]]);

        $api->gateway()->cancelar(self::ID);

        self::assertSame(['DELETE /subscriptions/' . self::ID], $api->rotasChamadas());
    }

    #[TestDox('Alterar o valor faz PUT /subscriptions/{id} em reais, levando o valor também à cobrança pendente')]
    public function testAlterarValor(): void
    {
        $api = new ApiAsaasFalsa(['PUT /subscriptions/' . self::ID => [200, ['id' => self::ID, 'value' => 139.8]]]);

        $api->gateway()->alterarValor(self::ID, 13980);

        self::assertSame(['PUT /subscriptions/' . self::ID], $api->rotasChamadas());
        self::assertSame(['value' => 139.8, 'updatePendingPayments' => true], $api->chamadas[0]['corpo']);
    }

    #[TestDox('Alterar o valor: recusa do Asaas vira FalhaNoGateway; valor zero nem chega a chamar')]
    public function testAlterarValorRecusado(): void
    {
        $api = new ApiAsaasFalsa(['PUT /subscriptions/' . self::ID => [400, ['errors' => [['code' => 'invalid_action', 'description' => 'tokenização']]]]]);
        try {
            $api->gateway()->alterarValor(self::ID, 0);
            self::fail('valor zero tem que falhar');
        } catch (FalhaNoGateway) {
            self::assertSame([], $api->chamadas);
        }

        $this->expectException(FalhaNoGateway::class);
        $api->gateway()->alterarValor(self::ID, 13980);
    }

    #[TestDox('Cancelar assinatura que o Asaas já removeu (404) não é erro')]
    public function testCancelarComNaoEncontradoNaoLanca(): void
    {
        $api = new ApiAsaasFalsa(['DELETE /subscriptions/' . self::ID => [404, ApiAsaasFalsa::naoEncontrado()]]);

        $api->gateway()->cancelar(self::ID);

        self::assertSame(['DELETE /subscriptions/' . self::ID], $api->rotasChamadas());
    }

    #[TestDox('Nota fiscal mensal: sem o código do serviço configurado, não chama nada')]
    public function testNotaFiscalSemCodigoNaoChamaNada(): void
    {
        $api = new ApiAsaasFalsa();

        $api->gateway(['aliquotaIss' => '2'])->configurarNotaFiscalMensal(self::ID);

        self::assertSame([], $api->chamadas);
    }

    #[TestDox('Nota fiscal mensal: com o código, manda o serviço, a data de emissão e os impostos certos')]
    public function testNotaFiscalComCodigoMandaOsImpostos(): void
    {
        $rota = 'POST /subscriptions/' . self::ID . '/invoiceSettings';
        $api = new ApiAsaasFalsa([$rota => [200, ['municipalServiceCode' => '01.07', 'effectiveDatePeriod' => 'ON_PAYMENT_CONFIRMATION']]]);

        $api->gateway([
            'codigoDoServicoDaNota' => '01.07',
            'descricaoDoServicoDaNota' => 'Suporte técnico em informática',
            'aliquotaIss' => '2',
            'aliquotaPis' => '0,65',
            'aliquotaCofins' => '3',
            'aliquotaIr' => '1.5',
        ])->configurarNotaFiscalMensal(self::ID);

        self::assertSame([
            'municipalServiceCode' => '01.07',
            'municipalServiceName' => 'Suporte técnico em informática',
            'effectiveDatePeriod' => 'ON_PAYMENT_CONFIRMATION',
            'observations' => 'Assinatura de espaço BlueJus',
            'taxes' => ['retainIss' => false, 'iss' => 2.0, 'pis' => 0.65, 'cofins' => 3.0, 'csll' => 0.0, 'inss' => 0.0, 'ir' => 1.5],
        ], $api->corpoEnviado($rota));
    }

    #[TestDox('Nota fiscal mensal: erro do Asaas vira FalhaNoGateway')]
    public function testNotaFiscalComErroViraFalha(): void
    {
        $api = new ApiAsaasFalsa(['POST /subscriptions/' . self::ID . '/invoiceSettings' => [400, ['errors' => [['code' => 'invalid_action', 'description' => 'Recurso indisponível.']]]]]);

        $this->expectException(FalhaNoGateway::class);
        $api->gateway(['codigoDoServicoDaNota' => '01.07'])->configurarNotaFiscalMensal(self::ID);
    }

    #[TestDox('O Asaas implementa a nota fiscal mensal opcional')]
    public function testImplementaANotaFiscalOpcional(): void
    {
        self::assertInstanceOf(EmiteNotaFiscalDaAssinatura::class, (new ApiAsaasFalsa())->gateway());
    }

    #[TestDox('O nome do item do checkout é cortado em 30 caracteres, sem imagem')]
    public function testNomeDoItemLimitadoA30(): void
    {
        $api = self::apiQueIniciaComSucesso();
        $pedido = new PedidoDeCheckout(
            'BJ000007-avancado-43',
            7,
            'avancado',
            'Avançado com Muito Espaço Extra',
            9990,
            new Pagador('Escritório de Teste', '12345678000195', 'financeiro@escritorio.test'),
            'https://bluejus.com.br/contribua?assinatura=ok',
            'https://bluejus.com.br/contribua#espaco',
        );

        $api->gateway()->iniciar($pedido);

        $item = $api->corpoEnviado('POST /checkouts')['items'][0];
        self::assertSame('BlueJus Avançado com Muito Esp', $item['name']);
        self::assertArrayNotHasKey('imageBase64', $item);
    }

    #[TestDox('Link de checkout que não é https vira FalhaNoGateway')]
    public function testLinkNaoHttpsViraFalha(): void
    {
        $api = new ApiAsaasFalsa([
            'POST /checkouts' => [200, array_merge(ApiAsaasFalsa::checkoutCriado(), ['link' => 'http://sandbox.asaas.com/checkoutSession/show?id=x'])],
        ]);

        $this->expectException(FalhaNoGateway::class);
        $api->gateway()->iniciar(self::pedido());
    }

    #[TestDox('Tempo esgotado vira FalhaNoGateway')]
    public function testTimeoutViraFalha(): void
    {
        $gateway = ApiAsaasFalsa::gatewaySobre(new MockHttpClient(static function (): MockResponse {
            throw new TimeoutException('Idle timeout reached for "https://api-sandbox.asaas.com/v3/subscriptions".');
        }));

        $this->expectException(FalhaNoGateway::class);
        $gateway->consultar(self::ID);
    }

    #[TestDox('Inadimplente: cobrança vencida depois da última paga, mesmo com a próxima PENDING já gerada')]
    public function testInadimplenteComPendenteFutura(): void
    {
        $api = self::apiDaAssinatura('ACTIVE', [
            ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19'),
            ApiAsaasFalsa::cobranca('pay_2', 'OVERDUE', '2026-10-19'),
            ApiAsaasFalsa::cobranca('pay_3', 'PENDING', '2026-11-19'),
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(SituacaoDaAssinatura::Inadimplente, $situacao->situacao);
        self::assertSame('2026-10-19', $situacao->pagaAte?->format('Y-m-d'), 'vale até o fim do período de setembro');
    }

    #[TestDox('Inadimplente: cobrança vencida sem nenhuma paga')]
    public function testInadimplenteSemNenhumaPaga(): void
    {
        $api = self::apiDaAssinatura('ACTIVE', [
            ApiAsaasFalsa::cobranca('pay_1', 'OVERDUE', '2026-10-19'),
            ApiAsaasFalsa::cobranca('pay_2', 'PENDING', '2026-11-19'),
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(SituacaoDaAssinatura::Inadimplente, $situacao->situacao);
        self::assertNull($situacao->pagaAte);
    }

    #[TestDox('Cobrança vencida mais antiga que a última paga não torna a assinatura inadimplente')]
    public function testVencidaAntigaNaoEInadimplencia(): void
    {
        $api = self::apiDaAssinatura('ACTIVE', [
            ApiAsaasFalsa::cobranca('pay_1', 'OVERDUE', '2026-08-19'),
            ApiAsaasFalsa::cobranca('pay_2', 'RECEIVED', '2026-09-19'),
            ApiAsaasFalsa::cobranca('pay_3', 'PENDING', '2026-10-19'),
        ]);

        self::assertSame(SituacaoDaAssinatura::Ativa, $api->gateway()->consultar(self::ID)->situacao);
    }

    /** @return iterable<string, array{0: string}> */
    public static function statusQueNaoSaoPagos(): iterable
    {
        yield 'estornada' => ['REFUNDED'];
        yield 'chargeback pedido' => ['CHARGEBACK_REQUESTED'];
        yield 'estorno em andamento' => ['REFUND_IN_PROGRESS'];
    }

    #[DataProvider('statusQueNaoSaoPagos')]
    #[TestDox('Estorno ou chargeback na cobrança mais recente não conta como pago')]
    public function testEstornoNaoContaComoPago(string $status): void
    {
        $api = self::apiDaAssinatura('ACTIVE', [
            ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19'),
            ApiAsaasFalsa::cobranca('pay_2', $status, '2026-10-19'),
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame('2026-10-19', $situacao->pagaAte?->format('Y-m-d'), 'só setembro conta como pago');
        self::assertSame(SituacaoDaAssinatura::Ativa, $situacao->situacao);
    }

    #[TestDox('Só cobrança estornada: Aguardando, sem pagaAte')]
    public function testSoEstornadaFicaAguardando(): void
    {
        $situacao = self::apiDaAssinatura('ACTIVE', [ApiAsaasFalsa::cobranca('pay_1', 'REFUNDED', '2026-10-19')])->gateway()->consultar(self::ID);

        self::assertSame(SituacaoDaAssinatura::Aguardando, $situacao->situacao);
        self::assertNull($situacao->pagaAte);
    }

    #[TestDox('Assinatura EXPIRED é Cancelada')]
    public function testExpiradaECancelada(): void
    {
        $api = self::apiDaAssinatura('EXPIRED', [ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-10-19')]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(SituacaoDaAssinatura::Cancelada, $situacao->situacao);
        self::assertSame('2026-11-19', $situacao->pagaAte?->format('Y-m-d'));
    }

    #[TestDox('Assinatura que não existe mais no Asaas (404): Cancelada, sem pagaAte, sem lançar')]
    public function testAssinaturaNaoEncontradaECancelada(): void
    {
        $api = new ApiAsaasFalsa(['GET /subscriptions/' . self::ID => [404, ApiAsaasFalsa::naoEncontrado()]]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(self::ID, $situacao->idDaAssinatura);
        self::assertSame(SituacaoDaAssinatura::Cancelada, $situacao->situacao);
        self::assertNull($situacao->pagaAte);
        self::assertSame(['GET /subscriptions/' . self::ID], $api->rotasChamadas());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function vencimentosNoFimDoMes(): iterable
    {
        yield '31/01 em ano comum' => ['2027-01-31', '2027-02-28'];
        yield '31/01 em ano bissexto' => ['2028-01-31', '2028-02-29'];
        yield '31/03' => ['2027-03-31', '2027-04-30'];
        yield '31/12 vira o ano' => ['2026-12-31', '2027-01-31'];
        yield 'dia comum' => ['2026-10-19', '2026-11-19'];
    }

    #[DataProvider('vencimentosNoFimDoMes')]
    #[TestDox('pagaAte é o mesmo dia no mês seguinte, limitado ao último dia do mês')]
    public function testPagaAteNoFimDoMes(string $vencimento, string $esperado): void
    {
        $situacao = self::apiDaAssinatura('ACTIVE', [ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', $vencimento)])->gateway()->consultar(self::ID);

        self::assertSame($esperado, $situacao->pagaAte?->format('Y-m-d'));
    }

    #[TestDox('Paginação das cobranças: segue o hasMore e para na página repetida')]
    public function testPaginacaoParaNaPaginaRepetida(): void
    {
        $api = self::apiComPaginas([
            0 => [ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19')],
            100 => [ApiAsaasFalsa::cobranca('pay_2', 'CONFIRMED', '2026-10-19')],
            200 => [ApiAsaasFalsa::cobranca('pay_2', 'CONFIRMED', '2026-10-19')],
        ]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(3, self::chamadasDePaginas($api));
        self::assertSame('2026-11-19', $situacao->pagaAte?->format('Y-m-d'), 'a cobrança da segunda página entrou');
    }

    #[TestDox('Paginação das cobranças: página vazia encerra mesmo com hasMore')]
    public function testPaginacaoParaNaPaginaVazia(): void
    {
        $api = self::apiComPaginas([0 => [ApiAsaasFalsa::cobranca('pay_1', 'RECEIVED', '2026-09-19')], 100 => []]);

        $situacao = $api->gateway()->consultar(self::ID);

        self::assertSame(2, self::chamadasDePaginas($api));
        self::assertSame(SituacaoDaAssinatura::Ativa, $situacao->situacao);
    }

    /** @param array<int, list<array<mixed>>> $paginas offset => cobranças; toda página responde hasMore true */
    private static function apiComPaginas(array $paginas): ApiAsaasFalsa
    {
        return new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura()],
            'GET /subscriptions/' . self::ID . '/payments' => static fn (array $query): array => [
                200,
                array_merge(ApiAsaasFalsa::cobrancasDaAssinatura($paginas[(int) ($query['offset'] ?? 0)] ?? []), ['hasMore' => true]),
            ],
        ]);
    }

    private static function chamadasDePaginas(ApiAsaasFalsa $api): int
    {
        return \count(array_keys($api->rotasChamadas(), 'GET /subscriptions/' . self::ID . '/payments', true));
    }

    private static function apiQueIniciaComSucesso(): ApiAsaasFalsa
    {
        return new ApiAsaasFalsa(['POST /checkouts' => [200, ApiAsaasFalsa::checkoutCriado()]]);
    }

    /** @param list<array<mixed>> $cobrancas */
    private static function apiDaAssinatura(string $status, array $cobrancas): ApiAsaasFalsa
    {
        return new ApiAsaasFalsa([
            'GET /subscriptions/' . self::ID => [200, ApiAsaasFalsa::assinatura($status)],
            'GET /subscriptions/' . self::ID . '/payments' => [200, ApiAsaasFalsa::cobrancasDaAssinatura($cobrancas)],
        ]);
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
            '203.0.113.10',
        );
    }
}
