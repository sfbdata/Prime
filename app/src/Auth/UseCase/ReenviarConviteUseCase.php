<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\ReenviarConviteInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ReenviarConviteUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(ReenviarConviteInput $input): Invitation
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Apenas convites pendentes podem ser reenviados.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Convite expirado. Crie um novo convite.');
        }

        if (!$invitation->podeReenviar()) {
            throw new \DomainException('Limite de reenvios atingido. Crie um novo convite.');
        }

        $invitation->incrementarReenvio();
        $this->em->flush();

        return $invitation;
    }
}
