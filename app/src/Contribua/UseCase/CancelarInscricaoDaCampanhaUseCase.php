<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Enum\SituacaoDaParticipacao;
use App\Contribua\Exception\CampanhaItemNaoEncontrado;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O Master cancela uma inscrição que ainda aguarda o Pix: a participação é removida e a pessoa, o CPF, a OAB e a vaga
 * ficam livres para uma nova inscrição.
 */
final class CancelarInscricaoDaCampanhaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string a mensagem de sucesso */
    public function executar(int $participacaoId): string
    {
        $this->em->wrapInTransaction(function () use ($participacaoId): void {
            $participacao = $this->em->find(CampanhaParticipacao::class, $participacaoId, LockMode::PESSIMISTIC_WRITE);
            if (!$participacao instanceof CampanhaParticipacao) {
                throw new CampanhaItemNaoEncontrado('Inscrição não encontrada.');
            }
            if ($participacao->getSituacao() !== SituacaoDaParticipacao::AguardandoPagamento) {
                throw new \DomainException('Só dá para cancelar inscrição que ainda aguarda o Pix.');
            }
            $this->em->remove($participacao);
        });

        return 'Inscrição cancelada.';
    }
}
