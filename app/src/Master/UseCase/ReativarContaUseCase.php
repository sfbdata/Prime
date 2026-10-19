<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Reativa uma conta suspensa (spec docs/specs/perfis-1.2-contas.md C1, C3, C6). É o ÚNICO caminho de
 * reativação: o menu da conta usa este UseCase e o bloco "Pedidos de exclusão de conta" (§3, quando a
 * entidade da frente 7d chegar) também vai usar.
 *
 * Quem dispara: um Master, no menu da conta em "Contas e escritórios", depois de confirmar no modal.
 * O controller exige ROLE_SUPER_ADMIN; a checagem de Master se repete aqui.
 * Objetivo: devolver o acesso normal aos usuários da conta.
 * Pré-condições: a conta existe, está suspensa (`isActive=false`) e NÃO foi excluída: conta com
 * `excluidoEm` está na quarentena da exclusão e sai dela por outro caminho (decisão Q1 da spec).
 * Fluxo principal: carrega a conta, aplica as recusas, grava `isActive=true`.
 * Fluxos alternativos: conta inexistente ({@see ContaNaoEncontrada}); ator sem ROLE_SUPER_ADMIN; conta excluída ou já ativa. Recusados
 * com mensagem.
 * Pós-condições: os usuários voltam a selecionar o escritório e a entrar nele.
 * Auditoria (C6): flush dentro de {@see EscopoDeAuditoriaDePlataforma::executarComoConta()}, com o
 * `tenant_id` da conta alvo.
 */
final class ReativarContaUseCase
{
    public function __construct(
        private readonly ContasDoSistemaRepository $contas,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
    ) {
    }

    public function executar(SituacaoDaContaInput $input): ContaAlteradaOutput
    {
        $this->exigirMaster($input->ator);
        $conta = $this->carregarConta($input->contaId);
        $this->exigirContaSuspensa($conta);

        $conta->setIsActive(true);
        // Ponto de extensão do contrato com a frente 7d (PR 5): com pedido de exclusão PENDENTE para
        // esta conta, chamar aqui `$pedido->marcarReativada($input->ator)` (PedidoDeExclusaoDeConta),
        // ANTES do flush abaixo, para o pedido e a conta mudarem na mesma transação.
        $this->escopoDeAuditoria->executarComoConta((int) $conta->getId(), fn () => $this->contas->salvar($conta, flush: true));

        return ContaAlteradaOutput::fromEntity($conta);
    }

    private function exigirMaster(User $ator): void
    {
        if (!$this->regraDosMasters->ehMaster($ator)) {
            throw new OperacaoDeContaRecusada('Só o Master pode suspender ou reativar contas.');
        }
    }

    private function carregarConta(int $contaId): Tenant
    {
        $conta = $this->contas->buscar($contaId);
        if ($conta === null) {
            throw new ContaNaoEncontrada('Conta não encontrada.');
        }

        return $conta;
    }

    private function exigirContaSuspensa(Tenant $conta): void
    {
        if ($conta->getExcluidoEm() !== null) {
            throw new OperacaoDeContaRecusada('Esta conta foi excluída e não pode ser reativada por aqui.');
        }

        if ($conta->isActive() === true) {
            throw new OperacaoDeContaRecusada('Esta conta já está ativa.');
        }
    }
}
