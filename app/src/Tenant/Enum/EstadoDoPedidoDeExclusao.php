<?php

declare(strict_types=1);

namespace App\Tenant\Enum;

/**
 * Estado do pedido de exclusão de conta feito pelo primário (PR 5, docs/specs/conta-pedido-exclusao.md;
 * contrato com a b5, spec perfis-1.2-contas C3/C4/C4b).
 *
 * - pendente: a conta está "suspensa a pedido" (Tenant ativo; a barreira (b) segura a equipe).
 * - desistida: um primário desistiu; a equipe volta.
 * - reativada: o Master reativou pelo painel.
 * - excluida: o Master excluiu; a conta entrou na quarentena (Tenant.excluidoEm). Recuperada depois pelo
 *   Master, o pedido continua "excluida" (com o escritório ativo = recuperada).
 */
enum EstadoDoPedidoDeExclusao: string
{
    case Pendente = 'pendente';
    case Desistida = 'desistida';
    case Reativada = 'reativada';
    case Excluida = 'excluida';
}
