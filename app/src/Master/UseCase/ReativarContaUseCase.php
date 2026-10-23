<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Ponto\Service\RegistroDePontoInicial;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;

/**
 * Reativa uma conta suspensa (spec docs/specs/perfis-1.2-contas.md C1, C3, C6). É o ÚNICO caminho de
 * reativação: o menu da conta usa este UseCase e o bloco "Pedidos de exclusão de conta" (§3, quando a
 * entidade da frente 7d chegar) também vai usar.
 *
 * Quem dispara: um Master, no menu da conta em "Contas e escritórios", depois de confirmar no modal.
 * O controller exige ROLE_SUPER_ADMIN; a checagem de Master se repete aqui.
 * Objetivo: devolver o acesso normal aos usuários da conta.
 * Pré-condições: a conta existe e está fora do ar: suspensa (`isActive=false`) ou em quarentena
 * (`excluidoEm` preenchido e ainda não purgada, C4b: "pode ser recuperada pelo Master").
 * Fluxo principal: carrega a conta, aplica as recusas, grava `isActive=true` e `excluidoEm=null`.
 * Fluxos alternativos: conta inexistente ({@see ContaNaoEncontrada}); ator sem ROLE_SUPER_ADMIN; conta já ativa. Recusados
 * com mensagem.
 * Pós-condições: os usuários voltam a selecionar o escritório e a entrar nele.
 * Auditoria (C6): flush dentro de {@see EscopoDeAuditoriaDePlataforma::executarComoConta()}, com o
 * `tenant_id` da conta alvo. Da quarentena, o rótulo é "Exclusão de conta cancelada".
 */
final class ReativarContaUseCase
{
    public function __construct(
        private readonly ContasDoSistemaRepository $contas,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly int $carenciaPurgaDias,
        private readonly RegistroDePontoInicial $registroDePonto,
    ) {
    }

    public function executar(SituacaoDaContaInput $input): ContaAlteradaOutput
    {
        $this->exigirMaster($input->ator);
        $conta = $this->carregarConta($input->contaId);
        // "Suspensa a pedido" (PR 5, C3): o Tenant continua ativo; reativar é decidir o pedido. Ao recuperar
        // da QUARENTENA, um pedido já em "excluida" não é tocado (C4b): só o pendente entra aqui.
        $pedido = $this->pedidos->pendenteDoEscritorio($conta);
        if ($pedido === null) {
            $this->exigirContaForaDoAr($conta);
        }

        $conta->setIsActive(true);
        // Recuperar da quarentena (C4b): a exclusão é desfeita. Conta só suspensa já tem excluidoEm nulo.
        $conta->setExcluidoEm(null);
        // Fora do ar, o choque não via este escritório: quem bater com outro escritório fica com a chave desligada.
        $this->registroDePonto->conferirAoReligarOEscritorio($conta);
        // Pedido e conta mudam na mesma transação (o flush abaixo).
        $gravar = fn () => $this->escopoDeAuditoria->executarComoConta((int) $conta->getId(), fn () => $this->contas->salvar($conta, flush: true));
        if ($pedido === null) {
            $gravar();

            return ContaAlteradaOutput::fromEntity($conta, $this->carenciaPurgaDias);
        }

        // Decisão e desistência do primário ao mesmo tempo: quem chega depois recebe 409.
        $pedido->marcarReativada($input->ator);
        $this->pedidos->gravarDecisaoSePendente($pedido, $gravar);

        return ContaAlteradaOutput::fromEntity($conta, $this->carenciaPurgaDias);
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

    /** Suspensa ou em quarentena; conta ativa (e não excluída) não tem o que reativar. */
    private function exigirContaForaDoAr(Tenant $conta): void
    {
        if ($conta->isActive() === true && $conta->getExcluidoEm() === null) {
            throw new OperacaoDeContaRecusada('Esta conta já está ativa.');
        }
    }
}
