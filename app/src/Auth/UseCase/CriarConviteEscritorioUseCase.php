<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConviteEscritorioUseCase
{
    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): Invitation
    {
        // Convite com o perfil de sistema faz do convidado um primário: só primário ou Master
        // (spec perfis-master D4). OperacaoDePrimarioRecusada é DomainException: vira flash.
        if ($input->tenantRole->isSystem()) {
            $this->regraDosPrimarios->garantirQuePodeGerirPrimarios($input->criadoPor, $input->tenant);
        }

        $email = strtolower(trim($input->email));

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser !== null && $this->userTenantRepository->existeVinculoAtivo($existingUser, $input->tenant)) {
            throw new \DomainException('Este usuário já é colaborador ativo deste escritório.');
        }
        // Quem está suspenso ou em análise só volta pela equipe da conta, pelo primário (spec equipe-da-conta §2).
        $situacao = $existingUser === null ? null : $this->userTenantRepository->findPorUserETenant($existingUser, $input->tenant)?->getSituacao();
        if ($situacao !== null && $situacao !== SituacaoNaEquipe::Aprovado) {
            throw new \DomainException('Esta pessoa está com o acesso suspenso ou em análise neste escritório. Altere a situação dela na equipe da conta.');
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
