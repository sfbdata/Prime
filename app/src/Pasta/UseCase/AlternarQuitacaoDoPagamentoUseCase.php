<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Pasta\Service\LancamentosDaPastaSobTrava;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca o pagamento como recebido, ou desfaz o recebimento.
 *
 * Um gesto só para os dois sentidos porque na tela é o mesmo clique no selo —
 * e porque desfazer precisa ser tão fácil quanto marcar: quem clica errado numa
 * lista de parcelas parecidas não pode ficar sem saída a não ser apagar a linha.
 *
 * ÊXITO E SUCUMBÊNCIA (decisão 3 do dono): marcar como recebido um previsto com
 * percentual CONGELA o valor: grava o calculado agora (`BaseDoPercentual`) e zera o
 * percentual, no mesmo flush. Desfazer não volta o percentual: a linha volta a ser
 * prevista com o valor fixo.
 *
 * A base é lida SOB A TRAVA da pasta, numa transação própria
 * (`LancamentosDaPastaSobTrava`): sem ela, o valor congelaria sobre a soma de quando o
 * request carregou a pasta, ou sobre a de uma correção simultânea. Com o Total contratado
 * em zero, recusa: gravar R$ 0,00 como recebido apagaria o percentual sem valor nenhum.
 */
final class AlternarQuitacaoDoPagamentoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BaseDoPercentual $baseDoPercentual,
        private readonly LancamentosDaPastaSobTrava $lancamentosSobTrava,
    ) {}

    /**
     * @throws \DomainException quando o previsto seria recebido sobre um Total contratado zero
     */
    public function executar(PastaPagamento $pagamento, ?\DateTimeImmutable $quando = null): PastaPagamento
    {
        if ($pagamento->estaPago() || $pagamento->getPercentual() === null) {
            $pagamento->alternarQuitacao($quando);
            $this->em->flush();

            return $pagamento;
        }

        // Sem `wrapInTransaction`, que FECHA o EntityManager na falha: o controller ainda
        // responde a recusa com o card.
        $this->em->beginTransaction();

        try {
            $this->congelarEReceber($pagamento, $quando);
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();

            throw $e;
        }

        return $pagamento;
    }

    private function congelarEReceber(PastaPagamento $pagamento, ?\DateTimeImmutable $quando): void
    {
        $pasta       = $pagamento->getPasta();
        $lancamentos = $pasta !== null ? $this->lancamentosSobTrava->travarPastaELerLancamentos($pasta) : [];

        // Relido sob a trava: outra pessoa pode ter recebido ou corrigido antes.
        if (!$pagamento->estaPago() && $pagamento->getPercentual() !== null) {
            $valor = $this->baseDoPercentual->valorCalculadoSobre($pagamento, $lancamentos);
            if (ValorEmReais::paraCentavos($valor) <= 0) {
                throw new \DomainException('O total contratado está zerado: lance os pagamentos do contrato antes de marcar este percentual como recebido.');
            }
            $pagamento->congelarValor($valor);
        }

        $pagamento->alternarQuitacao($quando);
        $this->em->flush();
    }
}
