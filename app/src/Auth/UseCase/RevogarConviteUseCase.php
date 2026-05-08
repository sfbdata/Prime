<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\RevogarConviteInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RevogarConviteUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(RevogarConviteInput $input): Invitation
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Apenas convites pendentes podem ser revogados.');
        }

        $invitation->revogar();
        $this->em->flush();

        return $invitation;
    }
}
