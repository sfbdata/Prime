<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaPagamento;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca o pagamento como recebido, ou desfaz o recebimento.
 *
 * Um gesto só para os dois sentidos porque na tela é o mesmo clique no selo —
 * e porque desfazer precisa ser tão fácil quanto marcar: quem clica errado numa
 * lista de parcelas parecidas não pode ficar sem saída a não ser apagar a linha.
 */
final class AlternarQuitacaoDoPagamentoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(PastaPagamento $pagamento, ?\DateTimeImmutable $quando = null): PastaPagamento
    {
        $pagamento->alternarQuitacao($quando);
        $this->em->flush();

        return $pagamento;
    }
}
