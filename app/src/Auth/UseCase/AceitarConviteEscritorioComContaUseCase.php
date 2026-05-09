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

        if ($this->userTenantRepository->existeVinculoAtivo($input->usuarioAtual, $tenant)) {
            throw new \DomainException('Você já é colaborador deste escritório.');
        }

        $userTenant = new UserTenant($input->usuarioAtual, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        // TODO Etapa 6: remover após refatoração das referências legadas a $user->getTenant().
        // Mantemos user.tenant_id em sincronia com UserTenant durante a transição.
        // Não sobrescrever se o user já tem tenant principal (preserva vínculo original).
        if ($input->usuarioAtual->getTenant() === null) {
            $input->usuarioAtual->setTenant($tenant);
        }
        if ($invitation->getTenantRole() !== null && $input->usuarioAtual->getTenantRole() === null) {
            $input->usuarioAtual->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($input->usuarioAtual);

        $this->em->persist($userTenant);
        $this->em->flush();

        return $userTenant;
    }
}
