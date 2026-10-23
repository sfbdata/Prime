<?php

declare(strict_types=1);

namespace App\Ponto\Twig;

use App\Entity\Auth\User;
use App\Ponto\Service\RegistroEmOutroEscritorioNoTurno;
use App\Repository\TenantRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `dias_com_registro_em_outro_escritorio(user, tenantId, competencia)`: os dias da folha da ficha do admin em que a
 * pessoa bateu em outro escritório com o ponto deste aberto (spec `ponto-esquecimento-nao-trava.md` R8). Só o sinal,
 * sem o escritório. Chamada só pela ficha do colaborador (`tenant/edit_user_role.html.twig`), que já conferiu a
 * permissão do admin no escritório da URL; fica na Twig para não crescer o `TenantController::editUserRole` legado.
 */
final class RegistroEmOutroEscritorioExtension extends AbstractExtension
{
    public function __construct(
        private readonly RegistroEmOutroEscritorioNoTurno $registroEmOutro,
        private readonly TenantRepository $tenants,
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
        if ($tenant === null || $competencia === null || $competencia === '') {
            return [];
        }

        return $this->registroEmOutro->daCompetencia($user, $tenant, $competencia);
    }
}
