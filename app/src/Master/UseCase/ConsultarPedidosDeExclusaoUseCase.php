<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Tenant\DTO\PedidoDeExclusaoPendenteOutput;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;

/**
 * Bloco "Pedidos de exclusão de conta" da seção "Contas e escritórios" do Painel do Master (desenho
 * 05 - Perfis 1.2, linha 148; spec docs/specs/conta-pedido-exclusao.md, contrato com a b5).
 *
 * Quem dispara: o painel, ao carregar /master/dados/contas (ROLE_SUPER_ADMIN no controller).
 * Objetivo: listar os pedidos pendentes de TODAS as contas, do mais antigo ao mais novo, para o Master
 * decidir (Reativar ou Excluir, pelos mesmos fluxos do menu da conta, que decidem o pedido junto).
 * Só leitura: o nome e o e-mail de quem pediu, a data e o motivo; nada do conteúdo jurídico.
 */
final class ConsultarPedidosDeExclusaoUseCase
{
    public function __construct(
        private readonly PedidoDeExclusaoDeContaRepository $pedidos,
    ) {
    }

    /**
     * @return list<array{contaId: int, escritorio: string, pedidoPorNome: ?string, pedidoPorEmail: ?string, criadoEm: string, motivo: string}>
     */
    public function executar(): array
    {
        return array_map(static fn (PedidoDeExclusaoPendenteOutput $p): array => [
            'contaId'        => $p->tenantId,
            'escritorio'     => $p->escritorio,
            'pedidoPorNome'  => $p->pedidoPorNome,
            'pedidoPorEmail' => $p->pedidoPorEmail,
            'criadoEm'       => $p->criadoEm->format(\DATE_ATOM),
            'motivo'         => $p->motivo,
        ], $this->pedidos->pendentes());
    }
}
