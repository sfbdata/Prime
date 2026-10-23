<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\RegistroDePontoInicial;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Exception\MembroDaEquipeNaoEncontrado;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Aprovar, voltar para análise ou suspender uma pessoa da equipe (desenho bj-contas.js, `situacao`; spec
 * docs/specs/equipe-da-conta.md §2). Quem: o primário do escritório ou o Master. Nunca sobre o primário, sobre um
 * Master ou sobre si mesmo. O vínculo fica ativo só quando aprovado, e as portas de acesso já barram o resto.
 * Auditado pelo AuditLogSubscriber (UserTenant é Auditavel).
 *
 * @throws OperacaoDePrimarioRecusada
 * @throws MembroDaEquipeNaoEncontrado vínculo de outro escritório ou inexistente
 */
final class AlterarSituacaoNaEquipeUseCase
{
    public function __construct(
        private readonly EquipeDoEscritorioRepository $equipe,
        private readonly RegraDosPrimarios $regra,
        private readonly RegistroDePontoInicial $registroDePonto,
    ) {
    }

    public function executar(User $ator, Tenant $tenant, int $vinculoId, SituacaoNaEquipe $situacao): UserTenant
    {
        if (!$this->regra->podeGerirPrimarios($ator, $tenant)) {
            throw new OperacaoDePrimarioRecusada('Somente o usuário primário pode gerir a equipe da conta.');
        }
        $vinculo = $this->equipe->membroDoEscritorio($vinculoId, $tenant) ?? throw new MembroDaEquipeNaoEncontrado();
        $this->exigirQuePodeAlterar($ator, $vinculo);

        if ($vinculo->getSituacao() !== $situacao) {
            $vinculo->alterarSituacao($situacao);
            $this->registroDePonto->conferirAoReligar($vinculo);   // de volta a aprovado: o choque confere a chave
            $this->equipe->gravar();
        }

        return $vinculo;
    }

    private function exigirQuePodeAlterar(User $ator, UserTenant $vinculo): void
    {
        if ($vinculo->getUser()->getId() === $ator->getId()) {
            throw new OperacaoDePrimarioRecusada('Você não pode alterar a sua própria situação.');
        }
        if ($vinculo->getTenantRole()?->isSystem() === true) {
            throw new OperacaoDePrimarioRecusada('A situação do usuário primário não pode ser alterada.');
        }
        if ($this->regra->ehMaster($vinculo->getUser())) {
            throw new OperacaoDePrimarioRecusada('A situação do Master não pode ser alterada.');
        }
    }
}
