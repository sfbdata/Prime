<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\RecusarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecusarConviteEscritorioUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(RecusarConviteEscritorioInput $input): Invitation
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não pode mais ser recusado.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if (strtolower($invitation->getEmail()) !== strtolower((string) $input->usuarioAtual->getEmail())) {
            throw new \DomainException('Este convite pertence a outro usuário.');
        }

        $invitation->recusar();
        $this->em->flush();

        return $invitation;
    }
}
