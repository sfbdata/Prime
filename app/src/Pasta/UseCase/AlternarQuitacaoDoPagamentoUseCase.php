<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\BaseDoPercentual;
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
 */
final class AlternarQuitacaoDoPagamentoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BaseDoPercentual $baseDoPercentual,
    ) {}

    public function executar(PastaPagamento $pagamento, ?\DateTimeImmutable $quando = null): PastaPagamento
    {
        if (!$pagamento->estaPago() && $pagamento->getPercentual() !== null) {
            $pagamento->congelarValor($this->baseDoPercentual->valorCalculado($pagamento));
        }

        $pagamento->alternarQuitacao($quando);
        $this->em->flush();

        return $pagamento;
    }
}
