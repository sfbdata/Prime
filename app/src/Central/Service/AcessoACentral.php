<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Entity\Participante;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ParticipanteRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;

/**
 * As duas guardas da Central (`docs/specs/central-comunicacao-a1.md` §2), num lugar só:
 *
 * 1. **Usar a Central** = vínculo ATIVO com o escritório da sessão — o mesmo critério de quem é colaborador.
 *    Sem permissão de módulo nova (D3).
 * 2. **Ler ou escrever numa conversa** = ser participante dela. É posse direta, sem `PermissionChecker`: o
 *    checker tem bypass de super admin e de papel de sistema, e aqui ninguém lê conversa alheia.
 */
final class AcessoACentral
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly ParticipanteRepository $participantes,
        private readonly UserRepository $usuarios,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function exigirVinculoAtivo(User $usuario, ?Tenant $tenant): Tenant
    {
        if ($tenant === null || !$this->vinculos->existeVinculoAtivo($usuario, $tenant)) {
            throw new SemAcessoACentralException();
        }

        return $tenant;
    }

    /** @throws ConversaNaoEncontradaException */
    public function participacao(int $conversaId, User $usuario, Tenant $tenant): Participante
    {
        return $this->participantes->daConversa($conversaId, $usuario, $tenant)
            ?? throw new ConversaNaoEncontradaException($conversaId);
    }

    /**
     * Ids de quem tem vínculo ativo no escritório, numa consulta — para a lista de conversas não perguntar uma
     * vez por conversa.
     *
     * @return array<int, true>
     */
    public function idsComVinculoAtivo(Tenant $tenant): array
    {
        $ids = [];
        foreach ($this->usuarios->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            $ids[(int) $colega->getId()] = true;
        }

        return $ids;
    }

    public function temVinculoAtivo(User $usuario, Tenant $tenant): bool
    {
        return $this->vinculos->existeVinculoAtivo($usuario, $tenant);
    }
}
