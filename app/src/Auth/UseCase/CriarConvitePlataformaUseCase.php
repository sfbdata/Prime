<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConvitePlataformaInput;
use App\Auth\Service\EmailDoConvite;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConvitePlataformaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarConvitePlataformaInput $input): Invitation
    {
        $email = EmailDoConvite::normalizar($input->email);

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        $invitation = new Invitation(
            email: $email,
            token: bin2hex(random_bytes(32)),
            type: Invitation::TIPO_PLATAFORMA,
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }
}
