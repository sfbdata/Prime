<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConvitePlataformaInput;
use App\Auth\DTO\ConviteEmitido;
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

    public function executar(CriarConvitePlataformaInput $input): ConviteEmitido
    {
        $email = EmailDoConvite::normalizar($input->email);

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        // O token puro só existe aqui e no e-mail: o banco guarda o hash (spec seguranca-a-convites, raiz B).
        $token      = Invitation::gerarToken();
        $invitation = new Invitation(
            email: $email,
            tokenHash: Invitation::hashDoToken($token),
            type: Invitation::TIPO_PLATAFORMA,
            expiresAt: new \DateTimeImmutable('+24 hours'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return new ConviteEmitido($invitation, $token);
    }
}
