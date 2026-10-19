<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Suspende uma conta (escritório) pelo painel do Master (spec docs/specs/perfis-1.2-contas.md C1, C2, C5, C6).
 *
 * Quem dispara: um Master (primário ou secundário), no menu da conta em "Contas e escritórios", depois
 * de confirmar no modal. O controller exige ROLE_SUPER_ADMIN; a checagem de Master se repete aqui.
 * Objetivo: tirar o acesso de todos os usuários da conta sem apagar nenhum dado.
 * Pré-condições: a conta existe, não foi excluída (`excluidoEm` nulo), está ativa e não foi criada
 * por um Master (Q2, regra em {@see RegraDosMasters::recusaParaSuspender()}).
 * Fluxo principal: carrega a conta, aplica as recusas, grava `isActive=false` e mantém `excluidoEm`
 * nulo (é isso que distingue suspensão de exclusão).
 * Fluxos alternativos: ator sem ROLE_SUPER_ADMIN; conta inexistente, excluída, já suspensa ou do
 * Master. Todos recusados com mensagem.
 * Pós-condições: os usuários perdem o acesso no request seguinte (o TenantContextValidatorListener
 * barra a cada request; não é preciso derrubar sessão). Nada é apagado.
 * Auditoria (C6): Tenant é Auditavel; o flush roda em {@see EscopoDeAuditoriaDePlataforma::executarComoConta()},
 * então a linha grava o `tenant_id` da CONTA ALVO e aparece no "Histórico de alterações" dela.
 */
final class SuspenderContaUseCase
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
        $this->exigirContaAtivaQueNaoEhDoMaster($conta);

        $conta->setIsActive(false);
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
            throw new OperacaoDeContaRecusada('Conta não encontrada.');
        }

        return $conta;
    }

    private function exigirContaAtivaQueNaoEhDoMaster(Tenant $conta): void
    {
        if ($conta->getExcluidoEm() !== null) {
            throw new OperacaoDeContaRecusada('Esta conta foi excluída e não pode ser suspensa.');
        }

        if ($conta->isActive() !== true) {
            throw new OperacaoDeContaRecusada('Esta conta já está suspensa.');
        }

        $recusa = $this->regraDosMasters->recusaParaSuspender($conta);
        if ($recusa !== null) {
            throw new OperacaoDeContaRecusada($recusa);
        }
    }
}
