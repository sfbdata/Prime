<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O pedido de escrita na pasta recebida de outra conta não é aceito (spec acesso-restrito-e-compartilhar §4.5.1, fatia
 * 9b): fase do outro lado do Contrato, Finalizar ou Reativar, ou categoria de documento do Financeiro. A mensagem vai
 * para a tela; o controller responde 422.
 */
final class EscritaNaPastaRecebidaRecusadaException extends \DomainException
{
}
