<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit\Asaas;

use App\Contribua\Gateway\Asaas\GatewayAsaas;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A API do Asaas de mentira para os testes do adaptador: responde por rota ('GET /customers') com os
 * exemplos da documentação oficial e guarda cada chamada recebida para conferência.
 *
 * As fixtures foram montadas a partir dos exemplos de resposta da documentação (outubro de 2026), com os
 * ids trocados por ids de teste; a URL de cada exemplo está acima do método que o devolve.
 */
final class ApiAsaasFalsa
{
    public const URL = 'https://api-sandbox.asaas.com/v3';
    public const CHAVE = 'chave-de-teste-sandbox';
    public const TOKEN_DO_WEBHOOK = 'token-do-webhook-de-teste-com-mais-de-32-caracteres';
    public const ID_DO_CLIENTE = 'cus_000005401844';
    public const ID_DA_ASSINATURA = 'sub_VXJBYgP2u0eO';
    public const ID_DO_CHECKOUT = '5bf7ac24-0b3b-4b64-9b5f-6c12e7a6f2a1';

    /** @var list<array{metodo: string, caminho: string, query: array<mixed>, corpo: mixed, cabecalhos: list<string>}> */
    public array $chamadas = [];

    /**
     * @param array<string, array{0: int, 1: array<mixed>|string}|\Closure(array<mixed>): array{0: int, 1: array<mixed>|string}> $rotas
     *        'METODO /caminho' => [status, corpo], ou uma função da query que devolve [status, corpo]
     */
    public function __construct(private readonly array $rotas = [])
    {
    }

    public function http(): MockHttpClient
    {
        return new MockHttpClient(fn (string $metodo, string $url, array $opcoes): MockResponse => $this->responder($metodo, $url, $opcoes));
    }

    /** @param array<string, string> $nfse argumentos nomeados de nota fiscal do construtor */
    public function gateway(array $nfse = [], string $token = self::TOKEN_DO_WEBHOOK): GatewayAsaas
    {
        return self::gatewaySobre($this->http(), $nfse, $token);
    }

    /** @param array<string, string> $nfse */
    public static function gatewaySobre(MockHttpClient $http, array $nfse = [], string $token = self::TOKEN_DO_WEBHOOK): GatewayAsaas
    {
        $argumentos = array_merge(
            [$http, new MockClock('2026-10-19 12:00:00', 'America/Sao_Paulo')],
            ['urlDaApi' => self::URL, 'chaveDaApi' => self::CHAVE, 'tokenDoWebhook' => $token],
            $nfse,
        );

        return new GatewayAsaas(...$argumentos);
    }

    /** Gateway cujo transporte falha em qualquer chamada (host fora do ar). */
    public static function gatewayForaDoAr(): GatewayAsaas
    {
        return self::gatewaySobre(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Não foi possível conectar ao host.'])));
    }

    /** @return list<string> 'METODO /caminho' de cada chamada, na ordem */
    public function rotasChamadas(): array
    {
        return array_map(static fn (array $c): string => $c['metodo'] . ' ' . $c['caminho'], $this->chamadas);
    }

    /** @return array<mixed> o corpo JSON da primeira chamada à rota */
    public function corpoEnviado(string $rota): array
    {
        foreach ($this->chamadas as $chamada) {
            if ($chamada['metodo'] . ' ' . $chamada['caminho'] === $rota) {
                return \is_array($chamada['corpo']) ? $chamada['corpo'] : [];
            }
        }

        throw new \LogicException('A rota ' . $rota . ' não foi chamada.');
    }

    /** @param array<string, mixed> $opcoes */
    private function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $caminho = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/v3'));
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $corpo = \is_string($opcoes['body'] ?? null) && $opcoes['body'] !== '' ? json_decode($opcoes['body'], true) : null;
        $this->chamadas[] = ['metodo' => $metodo, 'caminho' => $caminho, 'query' => $query, 'corpo' => $corpo, 'cabecalhos' => $opcoes['headers'] ?? []];

        $rota = $metodo . ' ' . $caminho;
        if (!isset($this->rotas[$rota])) {
            throw new \LogicException('Chamada inesperada à API do Asaas: ' . $rota);
        }
        $definicao = $this->rotas[$rota];
        [$status, $resposta] = $definicao instanceof \Closure ? $definicao($query) : $definicao;

        return new MockResponse(\is_string($resposta) ? $resposta : (string) json_encode($resposta), ['http_code' => $status]);
    }

    // ------------------------------------------------------------------ fixtures da documentação

    /**
     * Lista de clientes filtrada por externalReference.
     * Doc: https://docs.asaas.com/reference/list-customers
     *
     * @param list<array<mixed>> $clientes
     *
     * @return array<mixed>
     */
    public static function listaDeClientes(array $clientes): array
    {
        return ['object' => 'list', 'hasMore' => false, 'totalCount' => \count($clientes), 'limit' => 10, 'offset' => 0, 'data' => $clientes];
    }

    /**
     * Um cliente (criação e consulta devolvem o mesmo objeto).
     * Doc: https://docs.asaas.com/reference/create-new-customer
     * Doc: https://docs.asaas.com/reference/retrieve-a-single-customer
     *
     * @return array<mixed>
     */
    public static function cliente(string $id = self::ID_DO_CLIENTE, ?string $referencia = 'BJ000007'): array
    {
        return [
            'object' => 'customer', 'id' => $id, 'dateCreated' => '2026-10-19', 'name' => 'Escritório de Teste',
            'email' => 'financeiro@escritorio.test', 'company' => null, 'phone' => null, 'mobilePhone' => null,
            'address' => null, 'addressNumber' => null, 'complement' => null, 'province' => null, 'postalCode' => null,
            'cpfCnpj' => '12345678000195', 'personType' => 'JURIDICA', 'deleted' => false, 'additionalEmails' => null,
            'externalReference' => $referencia, 'notificationDisabled' => false, 'observations' => null,
            'municipalInscription' => null, 'stateInscription' => null, 'canDelete' => true, 'cannotBeDeletedReason' => null,
            'canEdit' => true, 'cannotEditReason' => null, 'city' => null, 'cityName' => null, 'state' => null, 'country' => 'Brasil',
        ];
    }

    /**
     * Checkout criado.
     * Doc: https://docs.asaas.com/reference/create-new-checkout
     * Doc: https://docs.asaas.com/docs/checkout-com-assinatura-recorrente
     *
     * @return array<mixed>
     */
    public static function checkoutCriado(bool $comLink = true): array
    {
        $checkout = [
            'id' => self::ID_DO_CHECKOUT, 'status' => 'ACTIVE', 'minutesToExpire' => 60,
            'billingTypes' => ['CREDIT_CARD'], 'chargeTypes' => ['RECURRENT'],
            'callback' => ['successUrl' => 'https://bluejus.com.br/contribua?assinatura=ok', 'cancelUrl' => 'https://bluejus.com.br/contribua#espaco', 'expiredUrl' => 'https://bluejus.com.br/contribua#espaco'],
            'items' => [['name' => 'BlueJus Essencial', 'description' => 'Espaço do plano Essencial', 'quantity' => 1, 'value' => 19.9]],
            'subscription' => ['cycle' => 'MONTHLY', 'nextDueDate' => '2026-10-19', 'endDate' => null],
            'customer' => self::ID_DO_CLIENTE, 'customerData' => null, 'installment' => null, 'externalReference' => null,
        ];
        if ($comLink) {
            $checkout['link'] = 'https://sandbox.asaas.com/checkoutSession/show?id=' . self::ID_DO_CHECKOUT;
        }

        return $checkout;
    }

    /**
     * Uma assinatura.
     * Doc: https://docs.asaas.com/reference/retrieve-a-single-subscription
     *
     * @return array<mixed>
     */
    public static function assinatura(string $status = 'ACTIVE', bool $removida = false): array
    {
        return [
            'object' => 'subscription', 'id' => self::ID_DA_ASSINATURA, 'dateCreated' => '2026-09-19',
            'customer' => self::ID_DO_CLIENTE, 'paymentLink' => null, 'billingType' => 'CREDIT_CARD', 'cycle' => 'MONTHLY',
            'value' => 19.9, 'nextDueDate' => '2026-11-19', 'endDate' => null, 'description' => 'BlueJus Essencial',
            'status' => $status, 'discount' => ['value' => 0, 'limitDate' => null, 'dueDateLimitDays' => 0, 'type' => 'FIXED'],
            'fine' => ['value' => 0, 'type' => 'FIXED'], 'interest' => ['value' => 0, 'type' => 'PERCENTAGE'],
            'deleted' => $removida, 'maxPayments' => null, 'externalReference' => null, 'checkoutSession' => self::ID_DO_CHECKOUT,
        ];
    }

    /**
     * Cobranças de uma assinatura.
     * Doc: https://docs.asaas.com/reference/list-payments-of-a-subscription
     *
     * @param list<array<mixed>> $cobrancas
     *
     * @return array<mixed>
     */
    public static function cobrancasDaAssinatura(array $cobrancas): array
    {
        return ['object' => 'list', 'hasMore' => false, 'totalCount' => \count($cobrancas), 'limit' => 100, 'offset' => 0, 'data' => $cobrancas];
    }

    /**
     * Uma cobrança (objeto payment).
     * Doc: https://docs.asaas.com/reference/retrieve-a-single-payment
     *
     * @return array<mixed>
     */
    public static function cobranca(string $id, string $status, string $vencimento): array
    {
        return [
            'object' => 'payment', 'id' => $id, 'dateCreated' => $vencimento, 'customer' => self::ID_DO_CLIENTE,
            'subscription' => self::ID_DA_ASSINATURA, 'installment' => null, 'checkoutSession' => null, 'paymentLink' => null,
            'value' => 19.9, 'netValue' => 18.92, 'originalValue' => null, 'interestValue' => null, 'description' => 'BlueJus Essencial',
            'billingType' => 'CREDIT_CARD', 'canBePaidAfterDueDate' => true, 'pixTransaction' => null, 'status' => $status,
            'dueDate' => $vencimento, 'originalDueDate' => $vencimento, 'paymentDate' => null,
            'clientPaymentDate' => \in_array($status, ['CONFIRMED', 'RECEIVED'], true) ? $vencimento : null,
            'installmentNumber' => null, 'invoiceUrl' => 'https://sandbox.asaas.com/i/' . substr($id, 4), 'invoiceNumber' => '07855054',
            'externalReference' => null, 'deleted' => false, 'anticipated' => false, 'anticipable' => false,
            'creditDate' => null, 'estimatedCreditDate' => null, 'transactionReceiptUrl' => null, 'nossoNumero' => null,
            'bankSlipUrl' => null, 'discount' => ['value' => 0, 'limitDate' => null, 'dueDateLimitDays' => 0, 'type' => 'FIXED'],
            'fine' => ['value' => 0, 'type' => 'FIXED'], 'interest' => ['value' => 0, 'type' => 'PERCENTAGE'],
            'postalService' => false, 'custody' => null, 'refunds' => null,
        ];
    }

    /**
     * Assinatura removida.
     * Doc: https://docs.asaas.com/reference/remove-subscription
     *
     * @return array<mixed>
     */
    public static function assinaturaRemovida(): array
    {
        return ['deleted' => true, 'id' => self::ID_DA_ASSINATURA];
    }

    /**
     * Erro 404 do Asaas.
     * Doc: https://docs.asaas.com/docs/c%C3%B3digos-de-erro
     *
     * @return array<mixed>
     */
    public static function naoEncontrado(): array
    {
        return ['errors' => [['code' => 'not_found', 'description' => 'O recurso solicitado não foi encontrado.']]];
    }

    /**
     * Webhook de assinatura (SUBSCRIPTION_CREATED e afins).
     * Doc: https://docs.asaas.com/docs/eventos-para-assinaturas
     */
    public static function avisoDeAssinatura(string $evento = 'SUBSCRIPTION_CREATED'): string
    {
        return (string) json_encode([
            'id' => 'evt_6561b631fa5580caadd00bf4fc4ef4dc&9193',
            'event' => $evento,
            'dateCreated' => '2026-10-19 11:11:04',
            'subscription' => array_merge(self::assinatura(), ['dateCreated' => '19/10/2026', 'nextDueDate' => '19/11/2026']),
        ]);
    }

    /**
     * Webhook de cobrança (PAYMENT_CONFIRMED e afins).
     * Doc: https://docs.asaas.com/docs/webhook-para-cobrancas
     */
    public static function avisoDeCobranca(string $evento, ?string $assinatura, ?string $cliente): string
    {
        $cobranca = self::cobranca('pay_080225913252', 'CONFIRMED', '2026-10-19');
        $cobranca['subscription'] = $assinatura;
        $cobranca['customer'] = $cliente;

        return (string) json_encode([
            'id' => 'evt_05b708f961d739ea7eba7e4db318f621&368604920',
            'event' => $evento,
            'dateCreated' => '2026-10-19 16:45:03',
            'payment' => $cobranca,
        ]);
    }

    /**
     * Webhook do Checkout (CHECKOUT_PAID e afins).
     * Doc: https://docs.asaas.com/docs/eventos-de-checkout
     */
    public static function avisoDeCheckout(string $evento = 'CHECKOUT_PAID'): string
    {
        return (string) json_encode([
            'id' => 'evt_37260be8159d4472b4458d3de13efc2d&15370',
            'event' => $evento,
            'dateCreated' => '2026-10-19 12:01:51',
            'checkout' => array_merge(self::checkoutCriado(), ['status' => 'PAID']),
        ]);
    }
}
