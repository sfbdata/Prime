<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\CampanhaDevolucao;
use App\Contribua\Exception\CampanhaItemNaoEncontrado;
use App\Contribua\Service\CampanhaVigente;
use App\Entity\Auth\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** O Master pagou a devolução por Pix: o pedido passa de pendente para devolvido. */
final class MarcarDevolucaoDaCampanhaComoPagaUseCase
{
    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string a mensagem de sucesso */
    public function executar(User $master, int $devolucaoId): string
    {
        $agora = $this->vigente->agora();
        $this->em->wrapInTransaction(function () use ($master, $devolucaoId, $agora): void {
            $devolucao = $this->em->find(CampanhaDevolucao::class, $devolucaoId, LockMode::PESSIMISTIC_WRITE);
            if (!$devolucao instanceof CampanhaDevolucao) {
                throw new CampanhaItemNaoEncontrado('Devolução não encontrada.');
            }
            $devolucao->marcarDevolvida($master, $agora);
        });

        return 'Devolução marcada como paga.';
    }
}
