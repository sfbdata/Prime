<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;

final class CriarConviteEscritorioUseCase
{
    /** Conta com o e-mail a confirmar não manda e-mail a terceiros (spec login-desenho-10, decisão c). */
    public const RECUSA_EMAIL_A_CONFIRMAR = 'Confirme o seu e-mail para convidar pessoas. Reenviamos o link de confirmação se você pedir.';

    public function __construct(
        private readonly InvitationRepository $invitationRepository,
        private readonly UserRepository $userRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): Invitation
    {
        if (!$input->criadoPor->isEmailConfirmado()) {
            throw new \DomainException(self::RECUSA_EMAIL_A_CONFIRMAR);
        }

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
