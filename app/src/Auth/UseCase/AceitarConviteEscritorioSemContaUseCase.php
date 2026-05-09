<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AceitarConviteEscritorioSemContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(AceitarConviteEscritorioSemContaInput $input): User
    {
        $invitation = $this->invitationRepository->encontrarPorToken($input->token);

        if ($invitation === null) {
            throw new \DomainException('Convite não encontrado.');
        }

        if ($invitation->getStatus() !== 'pending') {
            throw new \DomainException('Este convite não está mais disponível.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('Este convite está expirado.');
        }

        if ($this->userRepository->findOneBy(['email' => $invitation->getEmail()]) !== null) {
            throw new \DomainException('Já existe uma conta com este e-mail.');
        }

        $fullName = $input->fullName !== '' ? $input->fullName : $invitation->getFullName();
        if ($fullName === null || $fullName === '') {
            throw new \InvalidArgumentException('Nome completo é obrigatório.');
        }

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        $user = new User();
        $user->setEmail($invitation->getEmail());
        $user->setFullName($fullName);
        $user->setIsActive(true);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $input->senha));

        $userTenant = new UserTenant($user, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        // TODO Etapa 6: remover após refatoração das referências legadas a $user->getTenant().
        // Mantemos user.tenant_id em sincronia com UserTenant durante a transição.
        $user->setTenant($tenant);
        if ($invitation->getTenantRole() !== null) {
            $user->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($user);

        $this->em->persist($user);
        $this->em->persist($userTenant);
        $this->em->flush();

        return $user;
    }
}
