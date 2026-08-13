<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O que a reconciliação da dupla contagem FEZ (ou faria, na simulação) —
 * SPEC `docs/specs/cobranca-espelho-da-contabilidade.md` §17.8 e §17.11.
 *
 * 🔑 **Os totais são SEPARADOS de propósito, e somá-los numa manchete é proibido.** Juros, multa e
 * correção entram no `Obrigacao::valorExigivel()`; o honorário **não**. Um número único apresentaria
 * como "dívida que baixou" algo que em parte não move a conta de devedor nenhum — e foi exatamente
 * essa confusão que fez o total desta frente ser reportado errado três vezes.
 */
final readonly class ResultadoReconciliacao
{
    /**
     * @param list<array{obrigacaoId: int, unidade: string, referencia: ?string, casoId: int,
     *                   loteId: ?int, loteEmitidoEm: ?\DateTimeImmutable,
     *                   antes: array<string, int>, depois: array<string, int>,
     *                   removidoNoSaldo: int, removidoForaDoSaldo: int}> $corrigidas
     * @param list<array{obrigacaoId: int, unidade: string, referencia: ?string, motivo: string,
     *                   duplicadoNoSaldo: int, duplicadoForaDoSaldo: int}>  $puladas
     */
    public function __construct(
        public bool $aplicou,
        public int $candidatas,
        public array $corrigidas,
        public array $puladas,
        public int $casosComEvento,
    ) {
    }

    /** O que saiu (ou sairia) do SALDO do devedor — juros + multa + correção. É a conta que muda. */
    public function removidoDoSaldoEmCentavos(): int
    {
        return array_sum(array_column($this->corrigidas, 'removidoNoSaldo'));
    }

    /** O que saiu FORA do saldo — honorário. Não muda o que ninguém deve. */
    public function removidoForaDoSaldoEmCentavos(): int
    {
        return array_sum(array_column($this->corrigidas, 'removidoForaDoSaldo'));
    }

    /**
     * O dinheiro que ficou INFLADO por causa das puladas — o número que não pode sumir do relatório.
     *
     * Pular obrigação congelada é a decisão certa (congelada não é re-hidratada, e mexer nela é outra
     * decisão), mas **não é um no-op**: é valor inflado que permanece no banco, e sem congelamento ele
     * teria sido corrigido. Reportar "N puladas" sem o valor faria a linha parecer contabilidade
     * de rotina.
     */
    public function inflacaoQueFicouEmCentavos(): int
    {
        return array_sum(array_column($this->puladas, 'duplicadoNoSaldo'))
            + array_sum(array_column($this->puladas, 'duplicadoForaDoSaldo'));
    }

    /** Nenhuma dívida pode sumir entre a lista da régua e o que a reconciliação fez. */
    public function contasFecham(): bool
    {
        return count($this->corrigidas) + count($this->puladas) === $this->candidatas;
    }
}
