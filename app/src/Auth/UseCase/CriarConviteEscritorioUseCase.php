<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConviteEscritorioUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): Invitation
    {
        $email = strtolower(trim($input->email));

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null && $this->userTenantRepository->existeVinculoAtivo($existingUser, $input->tenant)) {
            throw new \DomainException('Este usuário já é colaborador ativo deste escritório.');
        }

        $invitation = new Invitation(
            email: $email,
            token: bin2hex(random_bytes(32)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setFullName($input->fullName);
        $invitation->setTenant($input->tenant);
        $invitation->setTenantRole($input->tenantRole);
        $invitation->setCreatedBy($input->criadoPor);

        $this->em->persist($invitation);
        $this->em->flush();

        return $invitation;
    }
}
