<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\ServiceDesk\DTO\RecargaSolicitadaOutput;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Pagamento\CobrancaNaoCriada;
use App\ServiceDesk\Pagamento\InstrucaoDePagamento;
use App\ServiceDesk\Pagamento\PedidoDeRecarga;
use App\ServiceDesk\Pagamento\ProvedorDeRecarga;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;

/**
 * O escritório pede para adicionar saldo (desenho 12, "Adicionar saldo"). Nasce um crédito
 * PENDENTE: o saldo só sobe quando a recarga é confirmada (`ConfirmarRecargaDeSaldoUseCase`).
 *
 * Ordem: grava o pendente ANTES de chamar o provedor, para que um pagamento que chegue sempre
 * encontre a recarga. Se o provedor garantir que não cobrou (`CobrancaNaoCriada`), o pendente é desfeito; qualquer outra falha
 * dele é ambígua e o pendente fica, para o pagamento que talvez exista achar a recarga.
 */
final class SolicitarRecargaDeSaldoUseCase
{
    /** Passou disso, o escritório está repetindo o pedido: pague ou espere as pendentes. */
    public const MAXIMO_DE_PENDENTES = 5;

    public function __construct(
        private readonly MovimentoDeSaldoRepository $movimentos,
        private readonly ProvedorDeRecarga $provedor,
    ) {
    }

    public function executar(SolicitarRecargaInput $input): RecargaSolicitadaOutput
    {
        $referencia = $input->referencia ?? $this->novaReferencia();
        $existente = null === $input->referencia ? null : $this->movimentos->findOneBy(['referencia' => $referencia]);
        if (null !== $existente) {
            // "Já paguei" clicado duas vezes: a recarga é a mesma, não grava outra.
            return $this->recargaJaGravada($input, $referencia, $existente);
        }
        $this->exigirPoucasPendentes($input);
        $movimento = $this->montarRecargaPendente($input, $referencia);
        $this->movimentos->salvar($movimento, flush: true);
        $instrucao = $this->iniciarNoProvedor($input, $referencia, $movimento);

        return new RecargaSolicitadaOutput($referencia, $input->valorCentavos, $instrucao);
    }

    private function recargaJaGravada(SolicitarRecargaInput $input, string $referencia, MovimentoDeSaldo $existente): RecargaSolicitadaOutput
    {
        if ($existente->getTenant()->getId() !== $input->tenant->getId()) {
            throw new \DomainException('Referência de recarga inválida.');
        }

        return new RecargaSolicitadaOutput($referencia, $existente->getValorCentavos(), $this->provedor->iniciar(new PedidoDeRecarga(
            (int) $input->tenant->getId(),
            $referencia,
            $existente->getValorCentavos(),
            $input->forma,
            'Recarga de saldo do Service Desk',
        )));
    }

    private function exigirPoucasPendentes(SolicitarRecargaInput $input): void
    {
        if ($this->movimentos->quantasRecargasPendentesDoEscritorio($input->tenant) >= self::MAXIMO_DE_PENDENTES) {
            throw new \DomainException('Há recargas demais aguardando confirmação. Conclua ou aguarde as anteriores.');
        }
    }

    private function iniciarNoProvedor(SolicitarRecargaInput $input, string $referencia, MovimentoDeSaldo $movimento): InstrucaoDePagamento
    {
        try {
            return $this->provedor->iniciar(new PedidoDeRecarga(
                (int) $input->tenant->getId(),
                $referencia,
                $input->valorCentavos,
                $input->forma,
                'Recarga de saldo do Service Desk',
            ));
        } catch (CobrancaNaoCriada $falha) {
            $this->movimentos->remover($movimento, flush: true);
            throw $falha;
        }
    }

    private function montarRecargaPendente(SolicitarRecargaInput $input, string $referencia): MovimentoDeSaldo
    {
        $motivo = 'Recarga por ' . ('pix' === $input->forma->value ? 'PIX' : 'cartão');

        return MovimentoDeSaldo::recargaPendente($input->tenant, $input->valorCentavos, $referencia, $input->forma, $motivo, $input->solicitante);
    }

    private function novaReferencia(): string
    {
        return 'BJSD' . strtoupper(bin2hex(random_bytes(6)));
    }
}
