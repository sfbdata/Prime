<?php

namespace App\Service;

use App\Tenant\Service\PermissoesDoAcessoRestrito;
use App\Conector\Service\ModoDoConector;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Quem pode o quê (docs/AUTORIZACAO.md). O vínculo da pessoa com o escritório é guardado para o request
 * (spec `permission-checker-memoria.md`, D1): a sidebar pergunta dezenas de vezes por página. Só a entidade
 * encontrada é guardada; `isActive()`, o papel e as permissões continuam lidos ao vivo nela, e a entrada que
 * o EntityManager não gerencia mais (removida, solta) é consultada de novo. Zera entre requests e entre
 * mensagens do worker (`ResetInterface`).
 */
class PermissionChecker implements ResetInterface
{
    /** @var array<string, UserTenant> vínculo encontrado, por "usuário:escritório" */
    private array $vinculos = [];

    public function __construct(
        private readonly ResourceAccessRepository $resourceAccessRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly NivelNoCadeado $nivelNoCadeado,
        // Teto do conector de I.A. (spec mcp-remoto-oauth 6.3, item 2): ligado, a pessoa do token perde os passes do
        // Master e do perfil de sistema e as permissões `admin.*`. Nulo (testes antigos que montam o checker à mão):
        // como desligado.
        private readonly ?ModoDoConector $modoDoConector = null,
        // Compartilhar pasta (spec acesso-restrito-e-compartilhar §4.4): o compartilhamento interno ativo vale como
        // permissão de ver a pasta, como o `ResourceAccess`. Nulos (testes antigos): sem compartilhamento.
        private readonly ?PastaCompartilhamentoRepository $compartilhamentos = null,
        private readonly ?ClockInterface $relogio = null,
    ) {}

    public function canAccessModule(User $user, ?Tenant $tenant, string $module): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, sprintf('modules.%s.view', $module));
    }

    public function canAdminister(User $user, ?Tenant $tenant, string $permission): bool
    {
        if ($this->modoDoConector?->valePara($user) === true) {
            return false; // teto do conector: nenhuma função de administrador para a pessoa do token
        }
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, $permission);
    }

    public function canAccessResource(User $user, ?Tenant $tenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($userTenant === null) {
            return false;
        }

        return $this->canAccessResourceComVinculo($user, $userTenant, $resourceType, $resourceId, $action);
    }

    /**
     * O MESMO {@see canAccessResource()}, para quem já tem o vínculo da pessoa com o escritório em mãos
     * (a consulta que o trouxe evita uma ida ao banco por pessoa, como na lista de quem acompanha a
     * pasta). O escritório é o do vínculo. Vínculo inativo ou de outra pessoa recusa, como o
     * `resolveUserTenant`; o super admin global passa, como lá.
     */
    public function canAccessResourceComVinculo(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if (!$userTenant->isActive() || !self::mesmoUsuario($userTenant->getUser(), $user)) {
            return false;
        }

        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return true;
        }

        if (!$this->cabeNoCadeadoDaPasta($user, $userTenant, $resourceType, $resourceId, $action)) {
            return false;
        }

        return $this->permiteORecurso($user, $userTenant, $resourceType, $resourceId, $action);
    }

    /**
     * O MESMO {@see canAccessResourceComVinculo()} para uma lista de itens de um tipo, com UMA consulta
     * aos acessos item a item (em vez de uma por item). A ordem das camadas não muda: super admin
     * global, vínculo ativo da própria pessoa, perfil de sistema, teto do cadeado (quem chama carrega
     * os cadeados da lista antes, `AcessoAPasta::niveisEmLote`, para ele não consultar por pasta),
     * acesso do item e permissão do papel. Sem vínculo (`null`) só o super admin passa, como no
     * `canAccessResource`.
     *
     * @param list<int> $resourceIds
     *
     * @return list<int> os ids permitidos, na ordem recebida
     */
    public function idsPermitidosComVinculo(User $user, ?UserTenant $userTenant, string $resourceType, array $resourceIds, string $action): array
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return $resourceIds;
        }
        if ($userTenant === null || !$userTenant->isActive() || !self::mesmoUsuario($userTenant->getUser(), $user)) {
            return [];
        }
        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return $resourceIds;
        }

        $noCadeado = array_values(array_filter($resourceIds, fn (int $id): bool => $this->cabeNoCadeadoDaPasta($user, $userTenant, $resourceType, $id, $action)));
        $permissao = sprintf('resources.%s.%s', $resourceType, $action);
        // Acesso restrito: a liberação item a item não passa da lista de liberação (como no `permiteORecurso`).
        $acessos   = PermissoesDoAcessoRestrito::bloqueia($userTenant->getTenantRole(), $permissao) ? [] : $this->resourceAccessRepository->findForUserAndResources($user, $resourceType, $noCadeado);
        $doPapel   = $this->hasPermissionFromUserTenant($userTenant, $permissao);
        $compartilhadas = $this->compartilhadaParaVer($resourceType, $action) && $this->compartilhamentos !== null
            ? $this->compartilhamentos->idsDasPastasCompartilhadasCom($user, $userTenant->getTenant(), $this->hoje()) : [];

        return array_values(array_filter($noCadeado, static fn (int $id): bool => (isset($acessos[$id]) && $acessos[$id]->allows($action)) || $doPapel || in_array($id, $compartilhadas, true)));
    }

    private function compartilhadaParaVer(string $resourceType, string $action): bool
    {
        return $resourceType === AccessRequest::RESOURCE_PASTA && $action === AccessRequest::ACTION_VIEW;
    }

    private function hoje(): \DateTimeImmutable
    {
        return ($this->relogio?->now() ?? new \DateTimeImmutable())->setTime(0, 0);
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * O `canAccessResource` SEM o teto do cadeado da pasta: a permissão que a pessoa teria se a pasta
     * estivesse livre. Só para o pedido de acesso (spec pasta-cadeado K5): quem não veria a pasta nem sem
     * cadeado não tem o que pedir ao primário. Nunca use para liberar uma tela.
     */
    public function canAccessResourceIgnoringPastaLock(User $user, ?Tenant $tenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->permiteORecurso($user, $userTenant, $resourceType, $resourceId, $action);
    }

    private function permiteORecurso(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
        $permissao = sprintf('resources.%s.%s', $resourceType, $action);
        // Acesso restrito: a liberação item a item também não passa da lista de liberação (spec acesso-restrito §5.1).
        if (PermissoesDoAcessoRestrito::bloqueia($userTenant->getTenantRole(), $permissao)) {
            return false;
        }
        $resourceAccess = $this->resourceAccessRepository->findForUserAndResource($user, $resourceType, $resourceId);
        if ($resourceAccess !== null && $resourceAccess->allows($action)) {
            return true;
        }
        if ($this->compartilhadaParaVer($resourceType, $action) && $this->compartilhamentos?->ativoPara($resourceId, $user, $this->hoje()) !== null) {
            return true;
        }

        return $this->hasPermissionFromUserTenant($userTenant, $permissao);
    }

    public function hasPermission(User $user, ?Tenant $tenant, string $permission): bool
    {
        if (str_starts_with($permission, 'admin.') && $this->modoDoConector?->valePara($user) === true) {
            return false; // teto do conector: `admin.*` nunca, nem pelo perfil do vínculo
        }
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($this->passeDoPerfilDeSistema($user, $userTenant)) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, $permission);
    }

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    /**
     * O teto do cadeado da pasta (spec pasta-cadeado §3), depois dos bypasses e ANTES do `ResourceAccess`
     * e do fallback por tipo: `view` exige pelo menos `Visualizar`, as demais ações exigem `Editar`. Assim
     * um `AccessRequest` aprovado nunca fura o cadeado. O processo cujas pastas são TODAS ocultas para a
     * pessoa também é recusado (K3). Outros tipos de recurso passam reto.
     */
    private function cabeNoCadeadoDaPasta(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($resourceType === AccessRequest::RESOURCE_PROCESSO) {
            // K3: o processo cujas pastas são todas ocultas para a pessoa não abre para ela.
            return !$this->nivelNoCadeado->processoOcultoComVinculo($user, $userTenant, $resourceId);
        }
        if ($resourceType === AccessRequest::RESOURCE_CLIENTE) {
            // Acesso restrito: só os clientes das pastas sob a responsabilidade da pessoa.
            return !$this->nivelNoCadeado->clienteForaDoEscopoComVinculo($user, $userTenant, $resourceId);
        }
        if ($resourceType !== AccessRequest::RESOURCE_PASTA) {
            return true;
        }

        $nivel = $this->nivelNoCadeado->nivelComVinculo($user, $userTenant, $resourceId);

        return $action === AccessRequest::ACTION_VIEW ? $nivel->podeVer() : $nivel->podeEditar();
    }

    /**
     * Administrador do escritório: vínculo ativo com o perfil de sistema (o mesmo bypass das checagens
     * acima), ou o super admin global. Quem vê todas as pastas no relatório de pastas sem processo
     * (`PastasVisiveisNoRelatorio`); o cadeado é aplicado à parte, por `AcessoAPasta::idsOcultosPara`.
     */
    public function isAdministradorDoEscritorio(User $user, ?Tenant $tenant): bool
    {
        if ($this->modoDoConector?->valePara($user) === true) {
            return false; // teto do conector
        }
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        return $this->passeDoPerfilDeSistema($user, $this->resolveUserTenant($user, $tenant));
    }

    private function isGlobalSuperAdmin(User $user): bool
    {
        if ($this->modoDoConector?->valePara($user) === true) {
            return false; // teto do conector: o Master conta pelo perfil do vínculo, como qualquer colega
        }

        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    /**
     * O passe do perfil de sistema (o primário passa por toda checagem). Pelo conector, para a pessoa do token, não
     * vale: ela conta pelo perfil do vínculo e pelas regras de item (spec mcp-remoto-oauth 6.3).
     */
    private function passeDoPerfilDeSistema(User $user, ?UserTenant $userTenant): bool
    {
        if ($this->modoDoConector?->valePara($user) === true) {
            return false;
        }

        return $userTenant?->getTenantRole()?->isSystem() === true;
    }

    private function resolveUserTenant(User $user, Tenant $tenant): ?UserTenant
    {
        $chave = ($user->getId() !== null && $tenant->getId() !== null) ? $user->getId() . ':' . $tenant->getId() : null;

        $ut = $chave !== null ? ($this->vinculos[$chave] ?? null) : null;
        if ($ut !== null && !$this->userTenantRepository->continuaGerenciado($ut)) {
            unset($this->vinculos[$chave]);
            $ut = null;
        }
        if ($ut === null) {
            $ut = $this->userTenantRepository->findOneBy(['user' => $user, 'tenant' => $tenant]);
            if ($ut !== null && $chave !== null) {
                $this->vinculos[$chave] = $ut;
            }
        }

        return ($ut?->isActive() === true) ? $ut : null;
    }

    public function reset(): void
    {
        $this->vinculos = [];
    }

    private function hasPermissionFromUserTenant(UserTenant $userTenant, string $permission): bool
    {
        $tenantRole = $userTenant->getTenantRole();

        if ($tenantRole === null) {
            return false;
        }

        // Acesso restrito às pastas sob responsabilidade: fora da lista de liberação, nada vale, nem marcado no perfil.
        if (PermissoesDoAcessoRestrito::bloqueia($tenantRole, $permission)) {
            return false;
        }

        // Teto do conector (6.3, item 3): sem o passe, o perfil de sistema tem toda permissão que não é `admin.*`; o
        // cadeado e o acesso item a item já foram conferidos antes, como para qualquer colega.
        if ($tenantRole->isSystem() && $this->modoDoConector?->valePara($userTenant->getUser()) === true
            && $this->modoDoConector->perfilDeSistemaTem($permission)) {
            return true;
        }

        foreach ($tenantRole->getTenantRolePermissions() as $tenantRolePermission) {
            if ($tenantRolePermission->getPermission()?->getCode() === $permission) {
                return true;
            }
        }

        return false;
    }
}
