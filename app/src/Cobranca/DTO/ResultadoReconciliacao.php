<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * O que a reconciliação da dupla contagem FEZ (ou faria, na simulação) —
 * SPEC `docs/specs/cobranca-espelho-da-contabilidade.md` §17.8 e §17.11.
 *
 * 🔑 **UM total só, e ele é dívida que baixou de verdade.** Aqui estava escrito o oposto — *"os totais
 * são SEPARADOS de propósito, e somá-los numa manchete é proibido"* —, porque juros, multa e correção
 * entravam no `Obrigacao::valorExigivel()` e o honorário não. A spec `cobranca-honorario-no-total.md`
 * REVOGOU essa distinção: o honorário entra como qualquer outro encargo. A preocupação que gerou a
 * proibição continua válida em espírito (não apresentar como "dívida que baixou" o que não move conta
 * de ninguém) — só que hoje **tudo** o que esta reconciliação tira move.
 */
final readonly class ResultadoReconciliacao
{
    /**
     * @param list<array{obrigacaoId: int, unidade: string, referencia: ?string, casoId: int,
     *                   loteId: ?int, loteEmitidoEm: ?\DateTimeImmutable,
     *                   antes: array<string, int>, depois: array<string, int>,
     *                   removidoNoSaldo: int, removidoForaDoSaldo: int,
     *                   duplicadoNoSaldo: int, duplicadoForaDoSaldo: int}> $corrigidas
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

    /**
     * O que saiu (ou sairia) do SALDO do devedor — juros + multa + correção **+ honorário**. É a conta
     * que muda, e desde `cobranca-honorario-no-total.md` ela é a conta INTEIRA: a INV-E2, que deixava
     * o honorário fora do exigível, foi revogada.
     */
    public function removidoDoSaldoEmCentavos(): int
    {
        return array_sum(array_column($this->corrigidas, 'removidoNoSaldo'));
    }

    /**
     * ⚠️ **Sempre 0 desde `cobranca-honorario-no-total.md`** — não há mais nada que saia "fora do
     * saldo". Só o honorário caía aqui, e ele agora está dentro. Mantido para a soma das duas metades
     * continuar fechando com o total; não use este número para afirmar que algo não muda o saldo.
     */
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

    /**
     * O total que a RÉGUA chamou de duplicado, no universo inteiro (corrigidas + puladas) — é contra
     * este número que a lista foi aprovada, e é ele que a {@see ExpectativaDaLista} confere.
     *
     * ⚠️ **Não é o efeito da escrita.** No honorário os dois divergem de propósito: a reconciliação
     * grava `Σ` da coluna L de todas as linhas, então a coluna L da própria linha `1.15` VOLTA para o
     * campo, e sai do banco menos do que a régua chamou de duplicado (INV-CE9). Conferir a aprovação
     * contra o efeito faria a trava disparar sempre que houvesse linha `1.15` com coluna L.
     */
    public function duplicadoTotalEmCentavos(): int
    {
        return array_sum(array_column($this->corrigidas, 'duplicadoNoSaldo'))
            + array_sum(array_column($this->corrigidas, 'duplicadoForaDoSaldo'))
            + array_sum(array_column($this->puladas, 'duplicadoNoSaldo'))
            + array_sum(array_column($this->puladas, 'duplicadoForaDoSaldo'));
    }

    /** Nenhuma dívida pode sumir entre a lista da régua e o que a reconciliação fez. */
    public function contasFecham(): bool
    {
        return count($this->corrigidas) + count($this->puladas) === $this->candidatas;
    }
}
