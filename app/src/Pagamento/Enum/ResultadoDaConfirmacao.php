<?php

declare(strict_types=1);

namespace App\Pagamento\Enum;

/** O que a confirmação fez. Regra de negócio não lança exceção: ela fecharia o EntityManager dentro da transação. */
enum ResultadoDaConfirmacao: string
{
    case Confirmado = 'confirmado';
    /** Pagou a mais: libera e o Master é avisado da diferença (decisão do dono, 27/10/2026). */
    case ConfirmadoComDiferenca = 'confirmado_com_diferenca';
    case JaConfirmado = 'ja_confirmado';
    /** Pagou a menos: fica divergente e não libera. */
    case ValorMenor = 'valor_menor';
    /** Cobrança cancelada, expirada ou devolvida que recebeu dinheiro: divergente para o Master decidir. */
    case CobrancaInativa = 'cobranca_inativa';
    /** O mesmo end_to_end_id já pagou outra cobrança. */
    case PagamentoRepetido = 'pagamento_repetido';
    case NaoEncontrado = 'nao_encontrado';

    public function liberaOConsumidor(): bool
    {
        return $this === self::Confirmado || $this === self::ConfirmadoComDiferenca;
    }
}
