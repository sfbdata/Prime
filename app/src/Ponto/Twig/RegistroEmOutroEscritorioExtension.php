<?php

declare(strict_types=1);

namespace App\Ponto\Twig;

use App\Entity\Auth\User;
use App\Ponto\Service\RegistroEmOutroEscritorioNoTurno;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `dias_com_registro_em_outro_escritorio(user, tenantId, competencia)`: os dias da folha da ficha do admin em que a
 * pessoa bateu em outro escritório com o ponto deste aberto (spec `ponto-esquecimento-nao-trava.md` R8). Só o sinal,
 * sem o escritório. Chamada pela ficha do colaborador (`tenant/edit_user_role.html.twig`); fica na Twig para não crescer
 * o `TenantController::editUserRole` legado. A guarda é dela, não de quem chama: só devolve dias quando o usuário logado
 * administra o escritório (`admin.users.manage` pelo `PermissionChecker`) e a pessoa tem vínculo ativo nele.
 */
final class RegistroEmOutroEscritorioExtension extends AbstractExtension
{
    public function __construct(
        private readonly RegistroEmOutroEscritorioNoTurno $registroEmOutro,
        private readonly TenantRepository $tenants,
        private readonly Security $seguranca,
        private readonly PermissionChecker $permissoes,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('dias_com_registro_em_outro_escritorio', [$this, 'dias'])];
    }

    /** @return array<string, true> */
    public function dias(User $user, int $tenantId, ?string $competencia): array
    {
        $tenant = $this->tenants->find($tenantId);
        $logado = $this->seguranca->getUser();
        if ($tenant === null || $competencia === null || $competencia === '' || !$logado instanceof User) {
            return [];
        }
        if (!$this->permissoes->canAdminister($logado, $tenant, 'admin.users.manage') || !$this->vinculos->existeVinculoAtivo($user, $tenant)) {
            return [];
        }

        return $this->registroEmOutro->daCompetencia($user, $tenant, $competencia);
    }
}
