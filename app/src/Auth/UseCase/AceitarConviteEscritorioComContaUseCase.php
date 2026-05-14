<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Entity\Auth\UserTenant;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AceitarConviteEscritorioComContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(AceitarConviteEscritorioComContaInput $input): UserTenant
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

        if (strtolower($invitation->getEmail()) !== strtolower((string) $input->usuarioAtual->getEmail())) {
            throw new \DomainException('Este convite não pertence à sua conta.');
        }

        $tenant = $invitation->getTenant();
        if ($tenant === null) {
            throw new \DomainException('Convite inválido: escritório não encontrado.');
        }

        $vinculo = $this->userTenantRepository->findPorUserETenant($input->usuarioAtual, $tenant);

        if ($vinculo !== null && $vinculo->isActive()) {
            throw new \DomainException('Você já é colaborador deste escritório.');
        }

        if ($vinculo !== null) {
            $vinculo->reativar();
            $vinculo->setTenantRole($invitation->getTenantRole());
            $vinculo->setUpdatedAt(new \DateTimeImmutable());
            $invitation->aceitar($input->usuarioAtual);
            $this->em->flush();

            return $vinculo;
        }

        $userTenant = new UserTenant($input->usuarioAtual, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($input->usuarioAtual);

        $this->em->persist($userTenant);
        $this->em->flush();

        return $userTenant;
    }
}
