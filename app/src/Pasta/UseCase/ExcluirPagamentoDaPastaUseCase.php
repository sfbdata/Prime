<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaPagamento;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apaga um lançamento de pagamento da pasta.
 *
 * É também o caminho de CORREÇÃO: o desenho aprovado não tem edição de linha,
 * então errar a descrição ou o valor se conserta apagando e lançando de novo.
 * A posse (pagamento desta pasta, deste escritório) é conferida antes, por
 * quem chama, com `findByIdAndPastaAndTenant`.
 */
final class ExcluirPagamentoDaPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(PastaPagamento $pagamento): void
    {
        $this->em->remove($pagamento);
        $this->em->flush();
    }
}
