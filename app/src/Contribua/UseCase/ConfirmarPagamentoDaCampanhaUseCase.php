<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Exception\CampanhaItemNaoEncontrado;
use App\Contribua\Service\CampanhaVigente;
use App\Entity\Auth\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** O Master conferiu o Pix no extrato: a inscrição passa de "aguardando pagamento" para "ativa" e o prazo da devolução começa. */
final class ConfirmarPagamentoDaCampanhaUseCase
{
    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string a mensagem de sucesso */
    public function executar(User $master, int $participacaoId): string
    {
        $agora = $this->vigente->agora();
        $this->em->wrapInTransaction(function () use ($master, $participacaoId, $agora): void {
            $participacao = $this->em->find(CampanhaParticipacao::class, $participacaoId, LockMode::PESSIMISTIC_WRITE);
            if (!$participacao instanceof CampanhaParticipacao) {
                throw new CampanhaItemNaoEncontrado('Inscrição não encontrada.');
            }
            $participacao->confirmarPagamento($master, $agora);
        });

        return 'Pagamento confirmado.';
    }
}
