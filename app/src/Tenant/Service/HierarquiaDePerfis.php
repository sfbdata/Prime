<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use Symfony\Contracts\Service\ResetInterface;

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
final class HierarquiaDePerfis implements ResetInterface
{
    public const NIVEL_SECUNDARIO = 1;
    public const NIVEL_PRIMARIO = 2;
    public const NIVEL_MASTER = 3;

    public const RECUSA_MESMO_NIVEL_OU_ACIMA = 'Seu perfil não pode editar um perfil do mesmo nível ou acima.';
    public const RECUSA_PROPRIO_PERFIL = 'Seu perfil não pode trocar o próprio perfil. Peça ao usuário primário do escritório.';
    public const RECUSA_PERFIL_ACIMA_DO_SEU = 'Seu perfil só pode atribuir perfis com permissões que ele também tem.';
    public const RECUSA_ALVO_ACIMA_DO_SEU = 'Seu perfil não pode mexer em quem tem permissões que ele não tem.';
    public const RECUSA_SENHA_DE_OUTRO_ESCRITORIO = 'Esta pessoa também usa outro escritório. A senha só pode ser trocada por ela, em Meu Perfil, ou pelo suporte.';

    /**
     * A chave de cada recusa no catálogo (spec idioma-do-sistema): o texto acima continua sendo o português, a fonte
     * (e o que os testes comparam); quem leva a recusa à tela traduz pela chave. O CatalogoDasRecusasTest garante
     * que o português do catálogo é igual ao daqui.
     */
    public const CHAVE_DA_RECUSA = [
        self::RECUSA_MESMO_NIVEL_OU_ACIMA => 'perfis.recusa.mesmo_nivel_ou_acima',
        self::RECUSA_PROPRIO_PERFIL => 'perfis.recusa.proprio_perfil',
        self::RECUSA_PERFIL_ACIMA_DO_SEU => 'perfis.recusa.perfil_acima_do_seu',
        self::RECUSA_ALVO_ACIMA_DO_SEU => 'perfis.recusa.alvo_acima_do_seu',
        self::RECUSA_SENHA_DE_OUTRO_ESCRITORIO => 'perfis.recusa.senha_de_outro_escritorio',
    ];

    /** @var array<string, int> nível do ator por "usuário:escritório", calculado uma vez por requisição */
    private array $niveis = [];

    public function __construct(
        private readonly RegraDosMasters $regraDosMasters,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function nivelDoAtor(User $ator, Tenant $tenant): int
    {
        // A lista e o convite perguntam por perfil: o nível sai uma vez por requisição (o kernel zera no reset).
        // Sem id (objeto ainda não gravado) não guarda.
        $chave = $ator->getId() !== null && $tenant->getId() !== null ? $ator->getId() . ':' . $tenant->getId() : null;
        if ($chave !== null && isset($this->niveis[$chave])) {
            return $this->niveis[$chave];
        }

        $nivel = $this->calcularNivelDoAtor($ator, $tenant);
        if ($chave !== null) {
            $this->niveis[$chave] = $nivel;
        }

        return $nivel;
    }

    public function reset(): void
    {
        $this->niveis = [];
    }

    private function calcularNivelDoAtor(User $ator, Tenant $tenant): int
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
     * C5 no edit-role: quem não é primário nem Master só mexe (perfil e senha) em quem tem o perfil atual contido no
     * dele. Alvo sem perfil não tem permissão nenhuma: passa.
     */
    public function podeMexerNoAlvo(User $ator, Tenant $tenant, ?TenantRole $perfilDoAlvo): bool
    {
        return $perfilDoAlvo === null || $this->podeAtribuirPerfil($ator, $tenant, $perfilDoAlvo);
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
        $proprio = self::mesmoUsuario($ator, $alvo) && !$this->podeTrocarOProprioPerfil($ator, $tenant);
        if ($atual !== null && ($proprio || !$this->podeMexerNoAlvo($ator, $tenant, $atual))) {
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
        if (!$this->podeMexerNoAlvo($ator, $tenant, $atual)) {
            return self::RECUSA_ALVO_ACIMA_DO_SEU;
        }

        return $novo !== null && !$this->podeAtribuirPerfil($ator, $tenant, $novo) ? self::RECUSA_PERFIL_ACIMA_DO_SEU : null;
    }

    /**
     * Senha no edit-role: a `RegraDosPrimarios::podeTrocarSenhaDe()` (sem mudança) somada às barreiras desta frente
     * (`recusaDaSenha`).
     */
    public function podeTrocarSenha(User $ator, Tenant $tenant, UserTenant $alvo): bool
    {
        return $this->regraDosPrimarios->podeTrocarSenhaDe($ator, $alvo) && $this->recusaDaSenha($ator, $tenant, $alvo) === null;
    }

    /**
     * As barreiras desta frente para a senha no edit-role, ou nulo quando passa:
     *  - C8: a senha é da CONTA (vale em todos os escritórios), mas quem a troca aqui só responde por este. Quem não é
     *    Master não troca a senha de quem tem vínculo em outro escritório (qualquer perfil e situação), senão um gestor
     *    deste escritório entraria, com a senha nova, num escritório onde a pessoa é primária. A própria pessoa troca
     *    a dela;
     *  - C5: quem não é primário nem Master não troca a senha de quem tem perfil fora do subconjunto dele.
     */
    public function recusaDaSenha(User $ator, Tenant $tenant, UserTenant $alvo): ?string
    {
        if ($this->regraDosMasters->ehMaster($ator) || self::mesmoUsuario($ator, $alvo->getUser())) {
            return null;
        }
        if ($this->userTenantRepository->existeVinculoEmOutroEscritorio($alvo->getUser(), $tenant)) {
            return self::RECUSA_SENHA_DE_OUTRO_ESCRITORIO;
        }

        return $this->podeMexerNoAlvo($ator, $tenant, $alvo->getTenantRole()) ? null : self::RECUSA_ALVO_ACIMA_DO_SEU;
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
