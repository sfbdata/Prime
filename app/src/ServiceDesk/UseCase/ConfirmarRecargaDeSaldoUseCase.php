<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\ServiceDesk\DTO\ConfirmarRecargaInput;
use App\ServiceDesk\DTO\RecargaConfirmadaOutput;
use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Enum\ResultadoDaConfirmacao;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;
use App\ServiceDesk\Service\TransacaoDoRazao;

/**
 * A recarga foi paga: o Master confirma à mão ou o gateway avisa pelo webhook. Idempotente por
 * `referencia` (recarga já confirmada não credita de novo) e por `idExterno` (o mesmo pagamento
 * não credita duas recargas). Valor pago MENOR que o pedido não credita e fica gravado como
 * divergência (a recarga segue pendente); MAIOR credita o que foi pago e devolve a sobra em
 * `diferencaCentavos`, para o chamador avisar o Master (decisão do dono, 27/10). Os desfechos são valores (`ResultadoDaConfirmacao`), não exceções: exceção dentro
 * da transação fecha o EntityManager e derrubaria o que o chamador faz depois.
 */
final class ConfirmarRecargaDeSaldoUseCase
{
    public function __construct(
        private readonly MovimentoDeSaldoRepository $movimentos,
        private readonly SaldoDoEscritorioRepository $saldos,
        private readonly TransacaoDoRazao $transacao,
    ) {
    }

    public function executar(ConfirmarRecargaInput $input): RecargaConfirmadaOutput
    {
        return $this->transacao->emTransacao(fn (): RecargaConfirmadaOutput => $this->confirmar($input));
    }

    private function confirmar(ConfirmarRecargaInput $input): RecargaConfirmadaOutput
    {
        $recarga = $this->movimentos->recargaPorReferenciaTravando($input->referencia);
        if (null === $recarga) {
            return new RecargaConfirmadaOutput(ResultadoDaConfirmacao::NaoEncontrada, null);
        }
        $saldo = $this->saldos->travarDoEscritorio($recarga->getTenant());
        $impedimento = $this->impedimento($recarga, $input);
        if (null !== $impedimento) {
            return new RecargaConfirmadaOutput($impedimento, $saldo->getSaldoCentavos());
        }

        $recarga->confirmar($input->idExterno, $input->origem, $input->confirmadoPor, $input->valorCentavos);
        $saldo->creditar($recarga->valorCreditadoCentavos());
        $this->movimentos->salvar($recarga);
        $this->saldos->salvar($saldo, flush: true);

        return new RecargaConfirmadaOutput(ResultadoDaConfirmacao::Confirmada, $saldo->getSaldoCentavos(), $recarga->sobraCentavos());
    }

    private function impedimento(MovimentoDeSaldo $recarga, ConfirmarRecargaInput $input): ?ResultadoDaConfirmacao
    {
        if (!$recarga->estaPendente()) {
            return ResultadoDaConfirmacao::JaConfirmada;
        }
        if ($input->valorCentavos < $recarga->getValorCentavos()) {
            $recarga->registrarDivergencia($input->valorCentavos);
            $this->movimentos->salvar($recarga, flush: true);

            return ResultadoDaConfirmacao::ValorDivergente;
        }
        if (null !== $input->idExterno && $this->movimentos->existeComIdExterno($input->idExterno)) {
            return ResultadoDaConfirmacao::PagamentoRepetido;
        }

        return null;
    }
}
