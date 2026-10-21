<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;

/**
 * Hierarquia dos perfis do escritório (spec perfis-1.2-permissoes-e-hierarquia, parte C; desenho 05 - Perfis 1.2,
 * L720-724 e L822-824). Fecha a escalada em que um secundário com `admin.roles.manage` editava o próprio perfil,
 * marcava qualquer permissão e excluía o perfil de um colega.
 *
 * Níveis: 3 Master, 2 primário ativo, 1 os demais (o ator); 2 o perfil de sistema, 1 os secundários (o perfil).
 * Só mexe (cria, edita, exclui) quem tem nível MAIOR que o do perfil. Quem é Master sai da RegraDosMasters e quem é
 * primário da RegraDosPrimarios: a regra deles não é reescrita aqui.
 *
 * O perfil de sistema continua só pela gestão de primários (C4): a tela dele é a gestão de primários
 * (`RegraDosPrimarios::podeGerirPrimarios`), sem formulário de permissões.
 */
final class HierarquiaDePerfis
{
    public const NIVEL_SECUNDARIO = 1;
    public const NIVEL_PRIMARIO = 2;
    public const NIVEL_MASTER = 3;

    public const RECUSA_MESMO_NIVEL_OU_ACIMA = 'Seu perfil não pode editar um perfil do mesmo nível ou acima.';
    public const RECUSA_PROPRIO_PERFIL = 'Seu perfil não pode trocar o próprio perfil. Peça ao usuário primário do escritório.';
    public const RECUSA_PERFIL_ACIMA_DO_SEU = 'Seu perfil só pode atribuir perfis com permissões que ele também tem.';

    public function __construct(
        private readonly RegraDosMasters $regraDosMasters,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function nivelDoAtor(User $ator, Tenant $tenant): int
    {
        if ($this->regraDosMasters->ehMaster($ator)) {
            return self::NIVEL_MASTER;
        }

        return $this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant) ? self::NIVEL_PRIMARIO : self::NIVEL_SECUNDARIO;
    }

    public function nivelDoPerfil(TenantRole $perfil): int
    {
        return $perfil->isSystem() ? self::NIVEL_PRIMARIO : self::NIVEL_SECUNDARIO;
    }

    /** C2: a lista de Perfis é do primário e do Master, ou de quem tem `admin.roles.manage` (só leitura). */
    public function podeVerALista(User $ator, Tenant $tenant): bool
    {
        return $this->nivelDoAtor($ator, $tenant) >= self::NIVEL_PRIMARIO
            || $this->permissionChecker->canAdminister($ator, $tenant, 'admin.roles.manage');
    }

    /** C2: a lista mostra só os perfis de nível até o do ator. */
    public function podeVerOPerfil(User $ator, Tenant $tenant, TenantRole $perfil): bool
    {
        return $this->nivelDoPerfil($perfil) <= $this->nivelDoAtor($ator, $tenant);
    }

    /** C3: perfil novo é sempre secundário; criar exige nível acima dele. */
    public function podeCriarPerfil(User $ator, Tenant $tenant): bool
    {
        return $this->nivelDoAtor($ator, $tenant) > self::NIVEL_SECUNDARIO;
    }

    /**
     * C3: editar só perfil de nível abaixo do ator. O perfil de sistema abre a gestão de primários (C4), de quem
     * gere primários, como sempre foi.
     */
    public function podeEditarPerfil(User $ator, Tenant $tenant, TenantRole $perfil): bool
    {
        if ($perfil->isSystem()) {
            return $this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant);
        }

        return $this->nivelDoAtor($ator, $tenant) > $this->nivelDoPerfil($perfil);
    }

    /** C3: excluir só perfil secundário e só com nível acima dele. O de sistema nunca é excluído. */
    public function podeExcluirPerfil(User $ator, Tenant $tenant, TenantRole $perfil): bool
    {
        return !$perfil->isSystem() && $this->nivelDoAtor($ator, $tenant) > $this->nivelDoPerfil($perfil);
    }

    /** C5: só primário e Master trocam o próprio perfil. */
    public function podeTrocarOProprioPerfil(User $ator, Tenant $tenant): bool
    {
        return $this->nivelDoAtor($ator, $tenant) >= self::NIVEL_PRIMARIO;
    }

    /**
     * C5: quem não é primário nem Master só atribui (edit-role, convite) perfil cujas permissões estão contidas nas
     * do perfil dele no escritório. Primário e Master atribuem qualquer secundário; o perfil de sistema segue a
     * regra dos primários, checada à parte em cada porta.
     */
    public function podeAtribuirPerfil(User $ator, Tenant $tenant, TenantRole $perfil): bool
    {
        if ($this->nivelDoAtor($ator, $tenant) >= self::NIVEL_PRIMARIO) {
            return true;
        }

        if ($perfil->isSystem()) {
            return false;
        }

        // Sem vínculo ativo ou com perfil nulo, o ator não tem permissão de perfil nenhuma (AUTORIZACAO §2b): só
        // o perfil vazio cabe nas dele.
        $doAtor = $this->userTenantRepository->findAtivoPorUserETenant($ator, $tenant)?->getTenantRole();
        $dele   = $doAtor === null ? [] : self::codigos($doAtor);

        return array_diff(self::codigos($perfil), $dele) === [];
    }

    /**
     * C5 no edit-role: os perfis que o ator pode oferecer ao alvo. Quem não troca o próprio perfil só vê o atual; os
     * demais perfis passam pelo subconjunto. O perfil atual do alvo segue na lista (manter não é atribuir).
     *
     * @param list<TenantRole> $perfis
     *
     * @return list<TenantRole>
     */
    public function perfisAtribuiveis(User $ator, Tenant $tenant, User $alvo, array $perfis, ?TenantRole $atual): array
    {
        if ($atual !== null && self::mesmoUsuario($ator, $alvo) && !$this->podeTrocarOProprioPerfil($ator, $tenant)) {
            return [$atual];
        }

        return array_values(array_filter(
            $perfis,
            fn (TenantRole $perfil): bool => $perfil === $atual || $this->podeAtribuirPerfil($ator, $tenant, $perfil)
        ));
    }

    /** C5 no POST do edit-role: a mensagem de recusa da troca de `$atual` para `$novo`, ou nulo quando pode. */
    public function recusaAoAtribuir(User $ator, Tenant $tenant, User $alvo, ?TenantRole $atual, ?TenantRole $novo): ?string
    {
        if ($novo === $atual) {
            return null;
        }
        if (self::mesmoUsuario($ator, $alvo) && !$this->podeTrocarOProprioPerfil($ator, $tenant)) {
            return self::RECUSA_PROPRIO_PERFIL;
        }

        return $novo !== null && !$this->podeAtribuirPerfil($ator, $tenant, $novo) ? self::RECUSA_PERFIL_ACIMA_DO_SEU : null;
    }

    /** Por id só quando os dois têm id: sem id, null === null daria "mesmo usuário" para qualquer um. */
    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /** @return list<string> */
    private static function codigos(TenantRole $perfil): array
    {
        $codigos = [];
        foreach ($perfil->getTenantRolePermissions() as $vinculo) {
            $codigo = $vinculo->getPermission()?->getCode();
            if ($codigo !== null) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }
}
