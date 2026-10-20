<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Cora;

use App\Contribua\Banco\BancoPix;
use App\Contribua\Banco\CobrancaPix;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Banco\ResultadoDoTeste;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\Repository\IntegracaoBancariaRepository;

/**
 * Adaptador da Cora (Integração Direta) para o apoio por PIX: QR Code Pix v2 (`POST /v2/invoices/`, só PIX)
 * e consulta (`GET /v2/invoices/{id}`). Fontes: developers.cora.com.br/reference/qr-code-pix-v2 e
 * /reference/consultar-detalhes-de-um-boleto-v2.
 */
final class BancoCora implements BancoPix
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

    public function consultar(string $idNoBanco): SituacaoDaCobranca
    {
        $r = $this->cliente->pedir($this->credenciais(), 'GET', '/v2/invoices/' . rawurlencode($idNoBanco));
        $status = (string) ($r['status'] ?? '');
        // Só a data: fica no fuso do PHP. Com hora e fuso: convertida para o fuso do PHP (a coluna grava sem fuso).
        $quando = is_string($r['occurrence_date'] ?? null) ? (new \DateTimeImmutable($r['occurrence_date']))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null;

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
        if (null === $i->getClientId() || '' === $i->getClientId() || !$i->temCertificado()) {
            throw new FalhaNoBanco('Informe o Client ID e envie o certificado antes de testar.');
        }

        return new CredenciaisCora($i->getAmbiente(), $i->getClientId(), $this->cofre->decifrar((string) $i->getCertificadoCifrado()), $this->cofre->decifrar((string) $i->getChaveCifrada()));
    }
}
