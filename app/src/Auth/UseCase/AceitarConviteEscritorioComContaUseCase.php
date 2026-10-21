<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Auth\Event\ConviteDeEscritorioAceito;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Auth\Service\VagaNoEscritorio;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class AceitarConviteEscritorioComContaUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly EventDispatcherInterface $eventos,
        private readonly VagaNoEscritorio $vagaNoEscritorio,
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

        // Convite de primário: quem convidou ainda pode dar esse acesso? (spec perfis-master D4)
        $this->regraDosPrimarios->garantirQueConviteDePrimarioSegueValido($invitation);

        $vinculo = $this->userTenantRepository->findPorUserETenant($input->usuarioAtual, $tenant);
        $this->exigirQueOConvitePodeAbrir($vinculo, $tenant);

        // Limite de usuários também no aceite (spec perfis-1.2-planos-e-limites §6): o convite pode ter saído com vaga.
        $this->vagaNoEscritorio->exigir((int) $tenant->getId());

        if ($vinculo !== null) {
            $vinculo->reativar();
            $vinculo->setTenantRole($invitation->getTenantRole());
            $vinculo->setUpdatedAt(new \DateTimeImmutable());
            $invitation->aceitar($input->usuarioAtual);
            $this->em->flush();
            $this->avisarAceite($invitation, $input->usuarioAtual, $tenant);

            return $vinculo;
        }

        $userTenant = new UserTenant($input->usuarioAtual, $tenant);
        if ($invitation->getTenantRole() !== null) {
            $userTenant->setTenantRole($invitation->getTenantRole());
        }

        $invitation->aceitar($input->usuarioAtual);

        $this->em->persist($userTenant);
        $this->em->flush();
        $this->avisarAceite($invitation, $input->usuarioAtual, $tenant);

        return $userTenant;
    }

    /** Já ativo, suspenso ou em análise: o convite não devolve o acesso; só o primário, pela equipe da conta. */
    private function exigirQueOConvitePodeAbrir(?UserTenant $vinculo, Tenant $tenant): void
    {
        if ($vinculo !== null && $vinculo->isActive()) {
            throw new \DomainException('Você já é colaborador deste escritório.');
        }
        if ($vinculo !== null && $vinculo->getSituacao() !== SituacaoNaEquipe::Aprovado) {
            throw new \DomainException($vinculo->getSituacao()->mensagemDeAcesso((string) $tenant->getName()));
        }
    }

    /** Depois do último flush: as boas-vindas do Comercial (comercial-e-emails.md §3.5). */
    private function avisarAceite(Invitation $invitation, User $user, Tenant $tenant): void
    {
        $this->eventos->dispatch(new ConviteDeEscritorioAceito(
            (int) $user->getId(),
            (int) $tenant->getId(),
            $invitation->getTenantRole()?->isSystem() === true,
        ));
    }
}
