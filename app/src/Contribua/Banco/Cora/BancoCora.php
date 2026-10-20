<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Cora;

use App\Contribua\Banco\BancoBoleto;
use App\Contribua\Banco\BancoPix;
use App\Contribua\Banco\BoletoEmitido;
use App\Contribua\Banco\CobrancaPix;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeBoleto;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Banco\ResultadoDoTeste;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\Repository\IntegracaoBancariaRepository;

/**
 * Adaptador da Cora (Integração Direta) para o apoio por PIX: QR Code Pix v2 (`POST /v2/invoices/`, só PIX)
 * e consulta (`GET /v2/invoices/{id}`). Fontes: developers.cora.com.br/reference/qr-code-pix-v2 e
 * /reference/consultar-detalhes-de-um-boleto-v2. Também emite o boleto de apoio (chave `bol`): o mesmo
 * `POST /v2/invoices/` com `BANK_SLIP` e `PIX`, sem multa e sem juros (developers.cora.com.br, "Emissão de
 * boleto registrado (v2)"). A consulta e a confirmação são as mesmas da cobrança PIX.
 */
final class BancoCora implements BancoPix, BancoBoleto
{
    public function __construct(
        private readonly ClienteCora $cliente,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly CofreDeCertificado $cofre,
    ) {
    }

    public function codigo(): string
    {
        return 'cora';
    }

    public function testarConexao(): ResultadoDoTeste
    {
        try {
            $credenciais = $this->credenciais();
        } catch (FalhaNoBanco $e) {
            return new ResultadoDoTeste(false, $e->getMessage());
        }
        try {
            $this->cliente->token($credenciais);
        } catch (FalhaNoBanco $e) {
            return new ResultadoDoTeste(false, 'A Cora recusou a conexão: ' . $e->getMessage());
        }

        return new ResultadoDoTeste(true, 'Conexão com a Cora confirmada.');
    }

    public function criarCobranca(PedidoDeCobrancaPix $pedido): CobrancaPix
    {
        $r = $this->cliente->pedir($this->credenciais(), 'POST', '/v2/invoices/', [
            'headers' => ['Idempotency-Key' => $pedido->chaveDeIdempotencia],
            'json' => [
                'code' => $pedido->codigo,
                'customer' => [
                    'name' => mb_substr($pedido->nomeDoEscritorio, 0, 60),
                    'document' => ['identity' => $pedido->cnpj, 'type' => 'CNPJ'],
                ],
                'services' => [['name' => 'Apoio ao BlueJus', 'description' => 'Contribuição voluntária, conta ' . $pedido->codigo, 'amount' => $pedido->valorCentavos]],
                'payment_terms' => ['due_date' => $pedido->vencimento->format('Y-m-d')],
                'payment_forms' => ['PIX'],
            ],
        ]);
        $id = $r['id'] ?? null;
        $emv = $r['pix']['emv'] ?? null;
        if (!is_string($id) || !is_string($emv) || '' === $emv) {
            throw new FalhaNoBanco('A Cora não devolveu o código PIX da cobrança.');
        }

        return new CobrancaPix($id, $emv);
    }

    public function emitirBoleto(PedidoDeBoleto $pedido): BoletoEmitido
    {
        if ($pedido->valorCentavos < self::MINIMO_CENTAVOS) {
            throw new \DomainException('O boleto tem valor mínimo de R$ 5,00.');
        }
        $r = $this->cliente->pedir($this->credenciais(), 'POST', '/v2/invoices/', [
            'headers' => ['Idempotency-Key' => $pedido->chaveDeIdempotencia],
            'json' => self::corpoDoBoleto($pedido),
        ]);

        return self::boletoDaResposta($r);
    }

    /** @return array<string, mixed> */
    private static function corpoDoBoleto(PedidoDeBoleto $pedido): array
    {
        // Apoio voluntário: sem `fine` e sem `interest` (multa e juros ficam de fora de propósito).
        return [
            'code' => $pedido->codigo,
            'customer' => [
                'name' => mb_substr($pedido->nomeDoPagador, 0, 60),
                'email' => $pedido->email,
                'document' => ['identity' => $pedido->documento, 'type' => $pedido->tipoDoDocumento()],
            ],
            'services' => [['name' => 'Apoio ao BlueJus', 'description' => mb_substr('Contribuição voluntária, conta ' . $pedido->codigo, 0, 100), 'amount' => $pedido->valorCentavos]],
            'payment_terms' => ['due_date' => $pedido->vencimento->format('Y-m-d')],
            'payment_forms' => ['BANK_SLIP', 'PIX'],
        ];
    }

    /** @param array<string, mixed> $r */
    private static function boletoDaResposta(array $r): BoletoEmitido
    {
        $id = $r['id'] ?? null;
        $boleto = is_array($r['payment_options']['bank_slip'] ?? null) ? $r['payment_options']['bank_slip'] : [];
        $linha = $boleto['digitable'] ?? null;
        $url = $boleto['url'] ?? null;
        // A URL vai para um link da tela: só https:// (nada de javascript: ou http).
        if (!is_string($id) || '' === $id || !is_string($linha) || '' === $linha || !is_string($url) || !str_starts_with(strtolower($url), 'https://')) {
            throw new FalhaNoBanco('A Cora não devolveu a linha digitável e o PDF do boleto.', 0, null, false);
        }
        $emv = $r['pix']['emv'] ?? null;

        return new BoletoEmitido($id, $linha, $url, is_string($emv) && '' !== $emv ? $emv : null);
    }

    public function consultar(string $idNoBanco): SituacaoDaCobranca
    {
        $r = $this->cliente->pedir($this->credenciais(), 'GET', '/v2/invoices/' . rawurlencode($idNoBanco));
        $status = (string) ($r['status'] ?? '');
        $quando = is_string($r['occurrence_date'] ?? null) ? new \DateTimeImmutable($r['occurrence_date']) : null;

        return new SituacaoDaCobranca(
            paga: 'PAID' === $status,
            valorPagoCentavos: (int) ($r['total_paid'] ?? 0),
            pagaEm: 'PAID' === $status ? ($quando ?? new \DateTimeImmutable()) : null,
            codigo: is_string($r['code'] ?? null) ? $r['code'] : null,
            cancelada: 'CANCELLED' === $status,
        );
    }

    private function credenciais(): CredenciaisCora
    {
        $i = $this->integracoes->atual();
        // As credenciais guardadas são do banco configurado: nunca vão para outro banco.
        if ($i->getBanco() !== $this->codigo()) {
            throw new FalhaNoBanco('A integração foi trocada para outro banco; esta cobrança não é mais consultada automaticamente.', 0, null, false);
        }
        if (null === $i->getClientId() || '' === $i->getClientId() || !$i->temCertificado()) {
            throw new FalhaNoBanco('Informe o Client ID e envie o certificado antes de testar.');
        }

        return new CredenciaisCora($i->getAmbiente(), $i->getClientId(), $this->cofre->decifrar((string) $i->getCertificadoCifrado()), $this->cofre->decifrar((string) $i->getChaveCifrada()));
    }
}
