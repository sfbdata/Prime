<?php
declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;

/**
 * A regra única de quem pode deixar de ser primário (spec perfis-master §2 D3/D4, §5 E1).
 *
 * Primário = vínculo ativo cujo perfil é o de sistema (isSystem). Duas travas, nesta ordem:
 *  1. o primário TITULAR (Tenant.criadoPor, quem abriu a conta) nunca é rebaixado;
 *  2. o escritório nunca fica sem primário ativo.
 *
 * Mora num serviço, e não dentro de um UseCase, porque há duas portas que trocam o perfil de
 * um vínculo: a remoção de sócio (RemoverPrimarioSocioUseCase) e a edição do colaborador
 * (TenantController::editUserRole). Regra copiada nas duas acabaria divergindo.
 */
final class RegraDosPrimarios
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    /** Master = ROLE_SUPER_ADMIN (spec D2). Mesmo critério do PermissionChecker::isGlobalSuperAdmin. */
    public function ehMaster(User $user): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    /**
     * Quem pode GERIR primários neste escritório: o Master ou um primário ativo dele.
     *
     * Vale para toda porta que cria ou tira um primário (Perfis, edit-role, convite) e para a
     * troca de senha de um primário. Uma permissão de secundário (`admin.roles.manage`,
     * `admin.users.manage`, `admin.users.invite`) não basta: com ela um secundário poderia se
     * promover, ou promover um cúmplice, ao perfil de sistema, que tem acesso total.
     */
    public function podeGerirPrimarios(User $ator, Tenant $tenant): bool
    {
        if ($this->ehMaster($ator)) {
            return true;
        }

        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($ator, $tenant);

        return $vinculo !== null && $this->ehPrimario($vinculo);
    }

    /**
     * @throws OperacaoDePrimarioRecusada
     */
    public function garantirQuePodeGerirPrimarios(User $ator, Tenant $tenant): void
    {
        if (!$this->podeGerirPrimarios($ator, $tenant)) {
            throw new OperacaoDePrimarioRecusada(
                'Só o primário do escritório ou o Master pode dar ou tirar o perfil de usuário primário.'
            );
        }
    }

    /**
     * Trocar a senha de alguém pelo painel: de secundário, quem já tem acesso ao painel; de
     * primário sócio, só primário ou Master; do titular, só o próprio titular ou o Master.
     */
    public function podeTrocarSenhaDe(User $ator, UserTenant $alvo): bool
    {
        if ($alvo->getTenantRole()?->isSystem() !== true) {
            return true;
        }

        if ($this->ehTitular($alvo)) {
            return $this->ehMaster($ator) || $this->mesmoUsuario($ator, $alvo->getUser());
        }

        return $this->podeGerirPrimarios($ator, $alvo->getTenant());
    }

    public function ehPrimario(UserTenant $vinculo): bool
    {
        return $vinculo->isActive() && $vinculo->getTenantRole()?->isSystem() === true;
    }

    public function ehTitular(UserTenant $vinculo): bool
    {
        $titular = $vinculo->getTenant()->getCriadoPor();
        if ($titular === null) {
            return false;
        }

        return $this->mesmoUsuario($titular, $vinculo->getUser());
    }

    /**
     * Proxy x entidade carregada: compara por id, mas só quando os dois têm id (sem id,
     * null === null daria "mesmo usuário" para qualquer um).
     */
    private function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * Lança se o vínculo (hoje primário) não puder deixar de sê-lo.
     *
     * Deve ser chamada ANTES do flush que troca o perfil: a contagem lê o banco, onde o vínculo
     * ainda está com o perfil de sistema.
     *
     * @throws OperacaoDePrimarioRecusada
     */
    public function garantirQuePodeDeixarDeSerPrimario(UserTenant $vinculo): void
    {
        if ($this->ehTitular($vinculo)) {
            throw new OperacaoDePrimarioRecusada(
                'O primário titular (quem abriu a conta) não pode deixar de ser primário.'
            );
        }

        if ($this->userTenantRepository->contarAdminsAtivos($vinculo->getTenant()) <= 1) {
            throw new OperacaoDePrimarioRecusada(
                'Este é o último primário do escritório. Promova outro primário antes de removê-lo.'
            );
        }
    }
}
