<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Pagamento\DTO\ConfirmarPagamentoInput;
use App\Pagamento\DTO\ConfirmarPagamentoOutput;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Enum\ResultadoDaConfirmacao;
use App\Pagamento\Event\PagamentoConfirmado;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * O banco confirmou um pagamento (já reconsultado por mTLS; o aviso do webhook sozinho não confirma nada).
 *
 * Quem: o webhook e a conciliação, sem usuário; o escritório sai da própria cobrança (spec §6.5).
 * Idempotente em três camadas: o aviso repetido é descartado antes (NotificacaoRecebida), a cobrança é travada
 * `FOR UPDATE` e relida, e o end_to_end_id é único. Resultado de regra nunca lança: lançar fecharia o EntityManager.
 * Valor menor → divergente, não libera. Valor maior → libera e o evento leva a diferença (decisão do dono, 27/10/2026).
 * O evento {@see PagamentoConfirmado} sai só depois do commit.
 */
final class ConfirmarPagamentoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function executar(ConfirmarPagamentoInput $input): ConfirmarPagamentoOutput
    {
        $pagamento = $this->pagamentos->porReferenciaDoAviso($input->referencia);
        if ($pagamento === null) {
            return new ConfirmarPagamentoOutput(ResultadoDaConfirmacao::NaoEncontrado);
        }

        $saida = $this->em->wrapInTransaction(fn (): ConfirmarPagamentoOutput => $this->confirmarTravado($pagamento, $input));
        $this->avisarOConsumidor($pagamento, $input, $saida);

        return $saida;
    }

    private function confirmarTravado(Pagamento $pagamento, ConfirmarPagamentoInput $input): ConfirmarPagamentoOutput
    {
        $this->pagamentos->travar($pagamento);
        if ($input->endToEndId !== null && $this->pagamentos->endToEndEmOutra($input->endToEndId, $pagamento)) {
            $this->registrar($pagamento, 'repetido', $input->confirmadoPor, 'end_to_end_id já usado em outra cobrança; ignorado.');

            return new ConfirmarPagamentoOutput(ResultadoDaConfirmacao::PagamentoRepetido, $pagamento->getId());
        }

        $resultado = $pagamento->confirmar($input->valorPagoCentavos, $input->endToEndId, $input->pagoEm, $input->confirmadoPor);
        if ($resultado !== ResultadoDaConfirmacao::JaConfirmado) {
            $pagamento->registrarPagador($input->pagadorNome, $input->pagadorDocumento);
            $this->registrar($pagamento, $resultado->value, $input->confirmadoPor, $this->resumo($pagamento, $input));
        }

        return new ConfirmarPagamentoOutput($resultado, $pagamento->getId(), $this->diferenca($pagamento, $resultado));
    }

    private function diferenca(Pagamento $pagamento, ResultadoDaConfirmacao $resultado): int
    {
        return $resultado === ResultadoDaConfirmacao::ConfirmadoComDiferenca
            ? (int) $pagamento->getValorPagoCentavos() - $pagamento->getValorCentavos()
            : 0;
    }

    private function registrar(Pagamento $pagamento, string $tipo, string $quem, string $resumo): void
    {
        $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, $tipo, $quem, $resumo));
    }

    private function resumo(Pagamento $pagamento, ConfirmarPagamentoInput $input): string
    {
        return sprintf('Recebido %d centavos de %d cobrados (%s).', $input->valorPagoCentavos, $pagamento->getValorCentavos(), $pagamento->getStatus()->value);
    }

    private function avisarOConsumidor(Pagamento $pagamento, ConfirmarPagamentoInput $input, ConfirmarPagamentoOutput $saida): void
    {
        if (!$saida->resultado->liberaOConsumidor()) {
            return;
        }
        $this->dispatcher->dispatch(new PagamentoConfirmado(
            (int) $pagamento->getId(),
            (int) $pagamento->getTenant()->getId(),
            $pagamento->getOrigem(),
            $pagamento->getOrigemId(),
            $pagamento->getReferencia(),
            $input->valorPagoCentavos,
            $input->endToEndId,
            $saida->diferencaCentavos,
        ));
    }
}
