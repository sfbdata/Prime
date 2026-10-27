<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Asaas;

use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\EmiteNotaFiscalDaAssinatura;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptador do Asaas (API v3) pelo caminho hospedado: um cliente Asaas por escritório e, a cada pedido, um
 * Asaas Checkout recorrente. O cartão nunca passa pelo BlueJus (spec contribua-planos-cora, §6).
 *
 * As variáveis de ambiente são lidas com `default::`: o build de produção apaga o `.env`, e o gateway sem
 * configuração não pode derrubar o container; ele responde `FalhaNoGateway` ao ser usado.
 */
final class GatewayAsaas implements GatewayDeAssinatura, EmiteNotaFiscalDaAssinatura
{
    public const CODIGO = 'asaas';

    /** Recomendação da documentação do Asaas para o tempo de espera das chamadas. */
    private const TIMEOUT_SEGUNDOS = 60;

    private const FUSO = 'America/Sao_Paulo';

    private const COBRANCAS_POR_PAGINA = 100;

    /** Teto de páginas de cobranças lidas numa consulta (100 por página: mais de 80 anos de mensalidades). */
    private const MAXIMO_DE_PAGINAS = 10;

    /** Estorno, chargeback e estorno em andamento NÃO contam como pagos. */
    private const STATUS_PAGOS = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];

    /** Limite do nome do item no Asaas Checkout. */
    private const TAMANHO_DO_NOME_DO_ITEM = 30;

    private const STATUS_ENCERRADOS = ['INACTIVE', 'EXPIRED'];

    private const EVENTOS_DE_ASSINATURA = ['SUBSCRIPTION_CREATED', 'SUBSCRIPTION_UPDATED', 'SUBSCRIPTION_INACTIVATED', 'SUBSCRIPTION_DELETED'];

    /** Só disparam a reconsulta: a situação vem sempre de `consultar`. */
    private const EVENTOS_DE_COBRANCA = [
        'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED', 'PAYMENT_OVERDUE', 'PAYMENT_REFUNDED',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'PAYMENT_DELETED', 'PAYMENT_RESTORED',
        'PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_REFUND_IN_PROGRESS',
    ];

    private const EVENTOS_DE_CHECKOUT = ['CHECKOUT_PAID', 'CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED'];

    private readonly string $urlDaApi;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClockInterface $relogio,
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_URL_API')]
        string $urlDaApi = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_API_KEY')]
        private readonly string $chaveDaApi = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_TOKEN_WEBHOOK')]
        private readonly string $tokenDoWebhook = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_CODIGO_SERVICO')]
        private readonly string $codigoDoServicoDaNota = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_DESCRICAO_SERVICO')]
        private readonly string $descricaoDoServicoDaNota = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_ISS')]
        private readonly string $aliquotaIss = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_PIS')]
        private readonly string $aliquotaPis = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_COFINS')]
        private readonly string $aliquotaCofins = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_CSLL')]
        private readonly string $aliquotaCsll = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_INSS')]
        private readonly string $aliquotaInss = '',
        #[Autowire(env: 'string:default::CONTRIBUA_ASAAS_NFSE_IR')]
        private readonly string $aliquotaIr = '',
    ) {
        $this->urlDaApi = rtrim(trim($urlDaApi), '/');
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }

    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
    {
        $idDoCliente = $this->clienteDoEscritorio($pedido);
        $checkout = $this->chamar('POST', '/checkouts', ['json' => $this->corpoDoCheckout($pedido, $idDoCliente)]);
        $id = self::texto($checkout, 'id') ?? throw new FalhaNoGateway('Asaas criou o checkout sem devolver o id.');
        $link = self::texto($checkout, 'link') ?? $this->enderecoDoCheckout() . '/checkoutSession/show?id=' . rawurlencode($id);
        if (!str_starts_with($link, 'https://')) {
            throw new FalhaNoGateway('Asaas devolveu um link de checkout que não é https (checkout ' . $id . ').');
        }

        return InicioDoCheckout::redirecionar($link, $id);
    }

    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
    {
        throw new \LogicException('O Asaas vai sempre pela página hospedada: o cartão nunca passa pelo servidor do BlueJus.');
    }

    public function consultar(string $idDaAssinatura): SituacaoNoGateway
    {
        $assinatura = $this->chamarOuNulo('GET', '/subscriptions/' . rawurlencode($idDaAssinatura));
        if ($assinatura === null) {
            // 404: a assinatura não existe mais no Asaas. O núcleo guarda o maior pagaAte que já conhecia.
            return new SituacaoNoGateway($idDaAssinatura, SituacaoDaAssinatura::Cancelada, null);
        }
        $cobrancas = $this->cobrancasDaAssinatura($idDaAssinatura);
        $idDoCliente = self::texto($assinatura, 'customer');
        $ultimaPaga = self::ultimaCobrancaPaga($cobrancas);
        $valor = $assinatura['value'] ?? null;

        return new SituacaoNoGateway(
            $idDaAssinatura,
            self::traduzirSituacao($assinatura, $cobrancas, $ultimaPaga),
            $ultimaPaga === null ? null : self::fimDoPeriodoPago($ultimaPaga),
            $idDoCliente === null ? null : $this->referenciaDoCliente($idDoCliente),
            $idDoCliente,
            is_numeric($valor) ? (int) round((float) $valor * 100) : null,
        );
    }

    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
    {
        $this->autenticarAviso($cabecalhos);

        try {
            $dados = json_decode($corpo, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($dados)) {
            return null;
        }
        $idDoEvento = self::texto($dados, 'id');
        $tipo = self::texto($dados, 'event');
        if ($idDoEvento === null || $tipo === null) {
            return null;
        }
        [$idDaAssinatura, $idDoCliente] = self::alvoDoEvento($tipo, $dados);
        if ($idDaAssinatura === null && $idDoCliente === null) {
            return null;
        }

        return new AvisoDoGateway($idDoEvento, $tipo, $idDaAssinatura, null, $idDoCliente);
    }

    public function cancelar(string $idDaAssinatura): void
    {
        // 404: a assinatura já foi removida no Asaas, e cancelar o que já está cancelado não é erro.
        $this->chamarOuNulo('DELETE', '/subscriptions/' . rawurlencode($idDaAssinatura));
    }

    /**
     * Liga a NFS-e automática da assinatura (`EmiteNotaFiscalDaAssinatura`). Sem o código do
     * serviço municipal configurado, a configuração fiscal ainda não foi feita: não chama nada.
     *
     * Doc: https://docs.asaas.com/reference/create-configuration-for-issuance-of-invoices
     *
     * @throws FalhaNoGateway
     */
    public function configurarNotaFiscalMensal(string $idDaAssinatura): void
    {
        if (trim($this->codigoDoServicoDaNota) === '') {
            return;
        }

        $this->chamar('POST', '/subscriptions/' . rawurlencode($idDaAssinatura) . '/invoiceSettings', ['json' => [
            'municipalServiceCode' => trim($this->codigoDoServicoDaNota),
            'municipalServiceName' => trim($this->descricaoDoServicoDaNota),
            'effectiveDatePeriod' => 'ON_PAYMENT_CONFIRMATION',
            'observations' => 'Assinatura de espaço BlueJus',
            'taxes' => [
                'retainIss' => false,
                'iss' => self::aliquota($this->aliquotaIss, 'ISS'),
                'pis' => self::aliquota($this->aliquotaPis, 'PIS'),
                'cofins' => self::aliquota($this->aliquotaCofins, 'COFINS'),
                'csll' => self::aliquota($this->aliquotaCsll, 'CSLL'),
                'inss' => self::aliquota($this->aliquotaInss, 'INSS'),
                'ir' => self::aliquota($this->aliquotaIr, 'IR'),
            ],
        ]]);
    }

    /** O cliente Asaas do escritório (externalReference `BJ<conta>`), criado na primeira vez. */
    private function clienteDoEscritorio(PedidoDeCheckout $pedido): string
    {
        $referencia = self::referenciaDaConta($pedido->referencia);
        $lista = $this->chamar('GET', '/customers', ['query' => ['externalReference' => $referencia]]);
        foreach (self::lista($lista) as $cliente) {
            $id = self::texto($cliente, 'id');
            if ($id !== null && ($cliente['deleted'] ?? false) !== true) {
                $this->atualizarClienteSeMudou($id, $cliente, $pedido);

                return $id;
            }
        }

        $novo = $this->chamar('POST', '/customers', ['json' => [
            'name' => $pedido->pagador->nome,
            'cpfCnpj' => $pedido->pagador->documento,
            'email' => $pedido->pagador->email,
            'externalReference' => $referencia,
            'notificationDisabled' => false,
        ]]);

        return self::texto($novo, 'id') ?? throw new FalhaNoGateway('Asaas criou o cliente ' . $referencia . ' sem devolver o id.');
    }

    /**
     * O cliente reaproveitado leva o CPF/CNPJ e o e-mail do pagador de agora (a nota sai em nome dele).
     * Doc: https://docs.asaas.com/reference/update-existing-customer
     *
     * @param array<mixed> $cliente
     */
    private function atualizarClienteSeMudou(string $idDoCliente, array $cliente, PedidoDeCheckout $pedido): void
    {
        $documentoAtual = preg_replace('/\D/', '', (string) ($cliente['cpfCnpj'] ?? ''));
        $emailAtual = mb_strtolower(trim((string) ($cliente['email'] ?? '')));
        if ($documentoAtual === $pedido->pagador->documento && $emailAtual === mb_strtolower(trim($pedido->pagador->email))) {
            return;
        }

        $this->chamar('POST', '/customers/' . rawurlencode($idDoCliente), ['json' => [
            'cpfCnpj' => $pedido->pagador->documento,
            'email' => $pedido->pagador->email,
        ]]);
    }

    /** @return array<string, mixed> */
    private function corpoDoCheckout(PedidoDeCheckout $pedido, string $idDoCliente): array
    {
        // A imagem do item (`items[].imageBase64`) NÃO é enviada: a documentação do Checkout se contradiz (a
        // referência da API a marca como obrigatória e o exemplo do guia de assinatura recorrente a omite).
        // O sandbox decide; se for obrigatória, entra aqui o logotipo em base64.
        $item = [
            'name' => mb_substr('BlueJus ' . $pedido->planoNome, 0, self::TAMANHO_DO_NOME_DO_ITEM),
            'description' => 'Espaço do plano ' . $pedido->planoNome,
            'quantity' => 1,
            'value' => $pedido->valorCentavos / 100,
        ];

        return [
            'billingTypes' => ['CREDIT_CARD'],
            'chargeTypes' => ['RECURRENT'],
            'minutesToExpire' => 60,
            'callback' => [
                'successUrl' => $pedido->urlDeRetorno,
                'cancelUrl' => $pedido->urlDeCancelamento,
                'expiredUrl' => $pedido->urlDeCancelamento,
            ],
            'items' => [$item],
            'subscription' => [
                'cycle' => 'MONTHLY',
                'nextDueDate' => $this->relogio->now()->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d'),
            ],
            'customer' => $idDoCliente,
        ];
    }

    /** Página do checkout no mesmo ambiente da API (sandbox ou produção). */
    private function enderecoDoCheckout(): string
    {
        $host = (string) parse_url($this->urlDaApi, \PHP_URL_HOST);

        return str_contains($host, 'sandbox') ? 'https://sandbox.asaas.com' : 'https://asaas.com';
    }

    /**
     * Todas as cobranças da assinatura, da mais recente para a mais antiga (vencimento, depois criação).
     *
     * @return list<array<mixed>>
     */
    private function cobrancasDaAssinatura(string $idDaAssinatura): array
    {
        $cobrancas = [];
        $pagina = 0;
        do {
            $resposta = $this->chamar('GET', '/subscriptions/' . rawurlencode($idDaAssinatura) . '/payments', ['query' => [
                'limit' => self::COBRANCAS_POR_PAGINA,
                'offset' => $pagina * self::COBRANCAS_POR_PAGINA,
            ]]);
            $novas = 0;
            foreach (self::lista($resposta) as $cobranca) {
                $id = self::texto($cobranca, 'id') ?? 'sem-id-' . \count($cobrancas);
                if (!isset($cobrancas[$id])) {
                    $cobrancas[$id] = $cobranca;
                    ++$novas;
                }
            }
            ++$pagina;
            // Página vazia ou repetida (nenhum id novo) encerra mesmo com hasMore: evita laço com a API.
        } while ($novas > 0 && ($resposta['hasMore'] ?? false) === true && $pagina < self::MAXIMO_DE_PAGINAS);

        $cobrancas = array_values(array_filter($cobrancas, static fn (array $c): bool => ($c['deleted'] ?? false) !== true));

        usort($cobrancas, static fn (array $a, array $b): int => [(string) ($b['dueDate'] ?? ''), (string) ($b['dateCreated'] ?? '')]
            <=> [(string) ($a['dueDate'] ?? ''), (string) ($a['dateCreated'] ?? '')]);

        return $cobrancas;
    }

    /** O `externalReference` do cliente (`BJ<conta>`), que liga a assinatura ao escritório. */
    private function referenciaDoCliente(string $idDoCliente): ?string
    {
        // 404: cliente removido no Asaas; a assinatura segue ligada pelo id do cliente.
        $cliente = $this->chamarOuNulo('GET', '/customers/' . rawurlencode($idDoCliente));

        return $cliente === null ? null : self::texto($cliente, 'externalReference');
    }

    /** @param array<string, string> $cabecalhos */
    private function autenticarAviso(array $cabecalhos): void
    {
        if ($this->tokenDoWebhook === '') {
            throw new AvisoNaoAutenticado('Token do webhook do Asaas não configurado (CONTRIBUA_ASAAS_TOKEN_WEBHOOK).');
        }
        $recebido = $cabecalhos['asaas-access-token'] ?? null;
        if (!\is_string($recebido) || !hash_equals($this->tokenDoWebhook, $recebido)) {
            throw new AvisoNaoAutenticado('Webhook do Asaas sem o asaas-access-token correto.');
        }
    }

    /**
     * Chama a API e devolve o JSON da resposta. Erro de transporte, tempo esgotado, status fora de 2xx ou
     * corpo que não é JSON viram `FalhaNoGateway`.
     *
     * @param array<string, mixed> $opcoes
     *
     * @return array<mixed>
     */
    private function chamar(string $metodo, string $caminho, array $opcoes = []): array
    {
        [$status, $conteudo] = $this->enviar($metodo, $caminho, $opcoes);

        return self::lerResposta($status, $conteudo, $metodo, $caminho);
    }

    /**
     * Como `chamar`, mas 404 (recurso que não existe mais no Asaas) devolve null em vez de falhar.
     *
     * @param array<string, mixed> $opcoes
     *
     * @return array<mixed>|null
     */
    private function chamarOuNulo(string $metodo, string $caminho, array $opcoes = []): ?array
    {
        [$status, $conteudo] = $this->enviar($metodo, $caminho, $opcoes);

        return $status === 404 ? null : self::lerResposta($status, $conteudo, $metodo, $caminho);
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array{0: int, 1: string} status e corpo
     */
    private function enviar(string $metodo, string $caminho, array $opcoes): array
    {
        if ($this->urlDaApi === '' || $this->chaveDaApi === '') {
            throw new FalhaNoGateway('Asaas sem configuração: defina CONTRIBUA_ASAAS_URL_API e CONTRIBUA_ASAAS_API_KEY.');
        }

        try {
            $resposta = $this->http->request($metodo, $this->urlDaApi . $caminho, $opcoes + [
                'headers' => ['access_token' => $this->chaveDaApi, 'Accept' => 'application/json', 'User-Agent' => 'BlueJus'],
                'timeout' => self::TIMEOUT_SEGUNDOS,
            ]);
            $status = $resposta->getStatusCode();
            $conteudo = $resposta->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new FalhaNoGateway(sprintf('Asaas indisponível em %s %s: %s', $metodo, $caminho, $e->getMessage()), 0, $e);
        }

        return [$status, $conteudo];
    }

    /** @return array<mixed> */
    private static function lerResposta(int $status, string $conteudo, string $metodo, string $caminho): array
    {
        if ($status < 200 || $status >= 300) {
            throw new FalhaNoGateway(sprintf('Asaas respondeu HTTP %d em %s %s%s.', $status, $metodo, $caminho, self::codigosDeErro($conteudo)));
        }

        return self::decodificar($conteudo, $metodo, $caminho);
    }

    /** @return array<mixed> */
    private static function decodificar(string $conteudo, string $metodo, string $caminho): array
    {
        if (trim($conteudo) === '') {
            return [];
        }

        try {
            $dados = json_decode($conteudo, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FalhaNoGateway(sprintf('Asaas respondeu algo que não é JSON em %s %s.', $metodo, $caminho), 0, $e);
        }
        if (!\is_array($dados)) {
            throw new FalhaNoGateway(sprintf('Asaas respondeu JSON fora do formato em %s %s.', $metodo, $caminho));
        }

        return $dados;
    }

    /** Só os códigos de erro do Asaas (ex.: invalid_cpfCnpj): a descrição pode repetir dado do cliente. */
    private static function codigosDeErro(string $conteudo): string
    {
        $dados = json_decode($conteudo, true);
        $erros = \is_array($dados) && \is_array($dados['errors'] ?? null) ? $dados['errors'] : [];
        $codigos = [];
        foreach ($erros as $erro) {
            if (\is_array($erro) && \is_string($erro['code'] ?? null)) {
                $codigos[] = $erro['code'];
            }
        }

        return $codigos === [] ? '' : ' (' . implode(', ', $codigos) . ')';
    }

    /**
     * Inadimplente quando há cobrança OVERDUE vencida depois da última paga (ou sem nenhuma paga), mesmo
     * que a próxima PENDING já exista: o Asaas gera cada cobrança 40 dias antes do vencimento
     * (https://docs.asaas.com/docs/faq-assinaturas), então a vencida quase nunca é a mais recente.
     *
     * @param array<mixed>       $assinatura
     * @param list<array<mixed>> $cobrancas
     * @param array<mixed>|null  $ultimaPaga
     */
    private static function traduzirSituacao(array $assinatura, array $cobrancas, ?array $ultimaPaga): SituacaoDaAssinatura
    {
        if (($assinatura['deleted'] ?? false) === true || \in_array($assinatura['status'] ?? null, self::STATUS_ENCERRADOS, true)) {
            return SituacaoDaAssinatura::Cancelada;
        }
        $vencimentoPago = $ultimaPaga === null ? '' : (string) ($ultimaPaga['dueDate'] ?? '');
        foreach ($cobrancas as $cobranca) {
            if (($cobranca['status'] ?? null) === 'OVERDUE' && (string) ($cobranca['dueDate'] ?? '') > $vencimentoPago) {
                return SituacaoDaAssinatura::Inadimplente;
            }
        }

        return $ultimaPaga === null ? SituacaoDaAssinatura::Aguardando : SituacaoDaAssinatura::Ativa;
    }

    /**
     * @param list<array<mixed>> $cobrancas da mais recente para a mais antiga
     *
     * @return array<mixed>|null
     */
    private static function ultimaCobrancaPaga(array $cobrancas): ?array
    {
        foreach ($cobrancas as $cobranca) {
            if (\in_array($cobranca['status'] ?? null, self::STATUS_PAGOS, true)) {
                return $cobranca;
            }
        }

        return null;
    }

    /**
     * Fim do período pago: o mesmo dia do vencimento no mês seguinte, limitado ao último dia daquele mês
     * (31/01 vira 28/02 ou 29/02, nunca 03/03).
     *
     * @param array<mixed> $cobrancaPaga
     */
    private static function fimDoPeriodoPago(array $cobrancaPaga): \DateTimeImmutable
    {
        $vencimento = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($cobrancaPaga['dueDate'] ?? ''), new \DateTimeZone(self::FUSO));
        if ($vencimento === false) {
            throw new FalhaNoGateway('Asaas devolveu uma cobrança paga sem vencimento legível.');
        }
        $mesSeguinte = $vencimento->modify('first day of next month');
        $dia = min((int) $vencimento->format('j'), (int) $mesSeguinte->format('t'));

        return $mesSeguinte->setDate((int) $mesSeguinte->format('Y'), (int) $mesSeguinte->format('n'), $dia);
    }

    /**
     * Qual assinatura e qual cliente o evento aponta.
     *
     * @param array<mixed> $dados
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function alvoDoEvento(string $tipo, array $dados): array
    {
        if (\in_array($tipo, self::EVENTOS_DE_ASSINATURA, true)) {
            $assinatura = self::objeto($dados, 'subscription');

            return [self::texto($assinatura, 'id'), self::texto($assinatura, 'customer')];
        }
        if (\in_array($tipo, self::EVENTOS_DE_COBRANCA, true)) {
            $cobranca = self::objeto($dados, 'payment');

            return [self::texto($cobranca, 'subscription'), self::texto($cobranca, 'customer')];
        }
        if (\in_array($tipo, self::EVENTOS_DE_CHECKOUT, true)) {
            return [null, self::texto(self::objeto($dados, 'checkout'), 'customer')];
        }

        return [null, null];
    }

    /** `BJ<conta>`: o prefixo da referência do pedido, antes do primeiro hífen. */
    private static function referenciaDaConta(string $referenciaDoPedido): string
    {
        $conta = strstr($referenciaDoPedido, '-', true);

        return $conta === false || $conta === '' ? $referenciaDoPedido : $conta;
    }

    /** Alíquota de imposto da nota (decimal, vírgula ou ponto); vazio vale 0. */
    private static function aliquota(string $valor, string $imposto): float
    {
        $normalizado = str_replace(',', '.', trim($valor));
        if ($normalizado === '') {
            return 0.0;
        }
        if (!is_numeric($normalizado)) {
            throw new FalhaNoGateway('Alíquota de ' . $imposto . ' inválida em CONTRIBUA_ASAAS_NFSE_' . $imposto . '.');
        }

        return (float) $normalizado;
    }

    /**
     * Os itens de uma resposta paginada do Asaas (`data`).
     *
     * @param array<mixed> $resposta
     *
     * @return list<array<mixed>>
     */
    private static function lista(array $resposta): array
    {
        $itens = $resposta['data'] ?? [];

        return \is_array($itens) ? array_values(array_filter($itens, 'is_array')) : [];
    }

    /**
     * @param array<mixed> $dados
     *
     * @return array<mixed>
     */
    private static function objeto(array $dados, string $chave): array
    {
        return \is_array($dados[$chave] ?? null) ? $dados[$chave] : [];
    }

    /** @param array<mixed> $dados */
    private static function texto(array $dados, string $chave): ?string
    {
        $valor = $dados[$chave] ?? null;

        return \is_string($valor) && $valor !== '' ? $valor : null;
    }
}
