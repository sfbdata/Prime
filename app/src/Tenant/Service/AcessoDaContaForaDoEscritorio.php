<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\InvitationRepository;
use App\Repository\UserTenantRepository;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;

/**
 * A conta (User, cuja senha vale em todos os escritórios) tem acesso a algum escritório além deste? Base da regra C8 da
 * troca de senha (spec perfis-1.2-permissoes-e-hierarquia; auditoria 1.04): quem troca a senha pelo edit-role deste
 * escritório não pode abrir, com a senha nova, o acesso que a pessoa tem fora dele.
 *
 * Conta como acesso fora:
 *  - vínculo (UserTenant) com outro escritório, em qualquer perfil e situação;
 *  - convite de escritório pendente e não expirado de outro escritório para o e-mail da conta: a conta logada o aceita
 *    pelo id (POST /convites/{id}/aceitar) e vira membro de lá;
 *  - convite do Trabalho Externo aceito e vigente (não revogado, dentro da validade) de demanda de outro escritório: dá
 *    o perfil Convidado na demanda. O convite só enviado não conta: o aceite exige o token do e-mail.
 */
final class AcessoDaContaForaDoEscritorio
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly InvitationRepository $invitationRepository,
        private readonly ConviteDemandaRepository $conviteDemandaRepository,
    ) {
    }

    public function existe(User $conta, Tenant $escritorio): bool
    {
        return $this->userTenantRepository->existeVinculoEmOutroEscritorio($conta, $escritorio)
            || $this->temConviteDeEscritorioPendente($conta, $escritorio)
            || $this->temConviteDeDemandaVigente($conta, $escritorio);
    }

    private function temConviteDeEscritorioPendente(User $conta, Tenant $escritorio): bool
    {
        $email = (string) $conta->getEmail();
        if ($email === '') {
            return false;
        }

        foreach ($this->invitationRepository->encontrarDeEscritorioPendentesPorEmail($email) as $convite) {
            // A consulta já filtra; a conferência aqui é a regra, para valer mesmo se a consulta mudar.
            if ($convite->getType() === Invitation::TIPO_ESCRITORIO
                && $convite->getStatus() === 'pending'
                && !$convite->isExpired()
                && self::outroEscritorio($convite->getTenant(), $escritorio)) {
                return true;
            }
        }

        return false;
    }

    private function temConviteDeDemandaVigente(User $conta, Tenant $escritorio): bool
    {
        $hoje = new \DateTimeImmutable('today');
        foreach ($this->conviteDemandaRepository->vigentesDo($conta, $hoje) as $convite) {
            if ($convite->vigente($hoje)
                && self::outroEscritorio($convite->getDemanda()->getTenant(), $escritorio)) {
                return true;
            }
        }

        return false;
    }

    /** Sem escritório (convite de plataforma) não é outro escritório. Por id quando os dois têm id. */
    private static function outroEscritorio(?Tenant $dono, Tenant $escritorio): bool
    {
        if ($dono === null || $dono === $escritorio) {
            return false;
        }

        return $dono->getId() === null || $escritorio->getId() === null || $dono->getId() !== $escritorio->getId();
    }
}
