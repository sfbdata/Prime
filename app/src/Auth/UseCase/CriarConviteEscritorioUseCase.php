<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\Invitation;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
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
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly UsuariosDoEscritorioRepository $usuariosDoEscritorio,
    ) {}

    public function executar(CriarConviteEscritorioInput $input): Invitation
    {
        // Convite com o perfil de sistema faz do convidado um primário: só primário ou Master
        // (spec perfis-master D4). OperacaoDePrimarioRecusada é DomainException: vira flash.
        if ($input->tenantRole->isSystem()) {
            $this->regraDosPrimarios->garantirQuePodeGerirPrimarios($input->criadoPor, $input->tenant);
        }

        $this->exigirVagaNoEscritorio($input);

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

    /**
     * Limite de usuários do plano ou do Master (spec perfis-1.2-planos-e-limites P8): a regra é da
     * Contribua (`RetratoDoEspaco::aceitaMaisUmUsuario`), que só recusa com o bloqueio ligado. Conta
     * quem já está no escritório (vínculo ativo de pessoa ativa), como o "Definir limite de usuários".
     */
    private function exigirVagaNoEscritorio(CriarConviteEscritorioInput $input): void
    {
        $tenantId = (int) $input->tenant->getId();
        $retrato = $this->situacaoDoEspaco->doEscritorio($tenantId);
        if (!$retrato->aceitaMaisUmUsuario($this->usuariosDoEscritorio->quantos($tenantId))) {
            throw new \DomainException(sprintf(
                'Este escritório chegou ao limite de %d usuários. Para convidar mais pessoas, amplie o plano ou fale com o suporte do BlueJus.',
                (int) $retrato->limiteUsuarios,
            ));
        }
    }
}
