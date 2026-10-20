<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\ExcluirContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use Psr\Clock\ClockInterface;

/**
 * Exclui uma conta pelo painel do Master (spec docs/specs/perfis-1.2-contas.md C4, C5, C6; Q1 do dono:
 * quarentena de 365 dias, como a exclusão que já existe).
 *
 * Quem dispara: um Master, no menu da conta em "Contas e escritórios", depois de digitar EXCLUIR no modal.
 * O controller exige ROLE_SUPER_ADMIN; a checagem de Master e a da palavra se repetem aqui.
 * Objetivo: tirar a conta do ar agora e deixá-la para a purga automática depois da carência
 * (`tenant_carencia_purga_dias`). Até lá, o Master pode recuperá-la ({@see ReativarContaUseCase}).
 * Pré-condições: a conta existe, ainda não foi excluída e não é a conta do Master
 * ({@see RegraDosMasters::recusaParaExcluir()}). Pode estar ativa ou SUSPENSA: o ExcluirEscritorioUseCase
 * antigo sai cedo com a conta inativa e por isso não serve aqui.
 * Fluxo principal: carrega, aplica as recusas, grava `isActive=false` e `excluidoEm=agora`.
 * Fluxos alternativos: conta inexistente ({@see ContaNaoEncontrada}); ator sem ROLE_SUPER_ADMIN;
 * palavra EXCLUIR ausente ou errada; conta já excluída; conta do Master. Recusados com mensagem.
 * Pós-condições: ninguém acessa a conta (a barreira da conta no TenantContextValidatorListener), ela sai
 * das listas e a purga (`app:purgar-dados-expirados`, TenantRepository::encontrarPurgaveis) a apaga depois
 * da carência. Os usuários nunca são apagados.
 * Auditoria (C6): flush dentro de {@see EscopoDeAuditoriaDePlataforma::executarComoConta()}, com o
 * `tenant_id` da conta alvo; o rótulo é "Conta excluída".
 */
final class ExcluirContaPeloMasterUseCase
{
    public const PALAVRA = 'EXCLUIR';

    public function __construct(
        private readonly ContasDoSistemaRepository $contas,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDeAuditoria,
        private readonly ClockInterface $relogio,
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
        private readonly int $carenciaPurgaDias,
    ) {
    }

    public function executar(ExcluirContaInput $input): ContaAlteradaOutput
    {
        $this->exigirMaster($input->ator);
        $conta = $this->carregarConta($input->contaId);
        $this->exigirPalavra($input->confirmacao);
        $this->exigirContaExcluivel($conta);

        $conta->setIsActive(false);
        $conta->setExcluidoEm($this->relogio->now());
        // Pedido de exclusão PENDENTE (PR 5, C4): decidido aqui, na mesma transação da conta (o flush abaixo).
        $this->pedidos->pendenteDoEscritorio($conta)?->marcarExcluida($input->ator, $this->relogio->now());
        $this->escopoDeAuditoria->executarComoConta((int) $conta->getId(), fn () => $this->contas->salvar($conta, flush: true));

        return ContaAlteradaOutput::fromEntity($conta, $this->carenciaPurgaDias);
    }

    private function exigirMaster(User $ator): void
    {
        if (!$this->regraDosMasters->ehMaster($ator)) {
            throw new OperacaoDeContaRecusada('Só o Master pode excluir contas.');
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

    private function exigirPalavra(string $confirmacao): void
    {
        if (trim($confirmacao) !== self::PALAVRA) {
            throw new OperacaoDeContaRecusada('Digite EXCLUIR para confirmar a exclusão.');
        }
    }

    private function exigirContaExcluivel(Tenant $conta): void
    {
        if ($conta->getExcluidoEm() !== null) {
            throw new OperacaoDeContaRecusada('Esta conta já foi excluída.');
        }

        $recusa = $this->regraDosMasters->recusaParaExcluir($conta);
        if ($recusa !== null) {
            throw new OperacaoDeContaRecusada($recusa);
        }
    }
}
