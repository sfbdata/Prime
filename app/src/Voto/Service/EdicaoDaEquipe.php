<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Voto\Entity\Assembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\EquipeSoDaAdministracaoException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * As regras comuns dos UseCases da equipe (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.2): só a Administração
 * edita, a assembleia é do escritório (guarda de posse) e está em rascunho; e a ligação da linha com a conta da pessoa,
 * que só existe para usuário com vínculo ATIVO no escritório (linha sem conta não dá acesso).
 */
final class EdicaoDaEquipe
{
    public function __construct(
        private readonly AcessoAAssembleia $acesso,
        private readonly AssembleiaRepository $assembleias,
        private readonly UserRepository $usuarios,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /**
     * @throws EquipeSoDaAdministracaoException quem edita não é a Administração
     * @throws AssembleiaNaoEncontradaException a assembleia não é do escritório
     * @throws OperacaoInvalidaNaAssembleiaException a assembleia não está em rascunho
     */
    public function assembleiaEditavel(User $ator, Tenant $tenant, int $assembleiaId): Assembleia
    {
        if (!$this->acesso->ehAdministracao($ator, $tenant)) {
            throw new EquipeSoDaAdministracaoException();
        }
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirEquipeAlteravel();

        return $assembleia;
    }

    /**
     * A conta do escritório com este e-mail, se for uma só e tiver vínculo ativo; sem conta, a linha fica só como cargo.
     *
     * @throws OperacaoInvalidaNaAssembleiaException a conta é da Administração: ela já edita todas as votações e nunca é
     *                                               restringida, então não entra na equipe
     */
    public function contaDoEmail(Tenant $tenant, string $email): ?User
    {
        $email = trim($email);
        if ('' === $email) {
            return null;
        }
        $contas = $this->usuarios->encontrarPorEmailIgnorandoCaixa($email);
        if (1 !== \count($contas) || !$this->vinculos->existeVinculoAtivo($contas[0], $tenant)) {
            return null;
        }

        if ($this->acesso->ehAdministracao($contas[0], $tenant)) {
            throw new OperacaoInvalidaNaAssembleiaException('A Administração já atua em todas as votações: não precisa ser indicada.');
        }

        return $contas[0];
    }
}
