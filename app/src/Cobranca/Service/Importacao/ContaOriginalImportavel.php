<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Uma linha da seção "Relação das contas originais" de uma aba de acordo: a dívida que EXISTIA antes e
 * que o acordo substituiu. Value object fonte-agnóstico (spec `cobranca-importar-acordos-detalhados.md`
 * §2/§3.2).
 *
 * A chave de casamento com a `Obrigacao` do sistema é **NN + competência**, nunca o NN sozinho — por
 * isso os dois campos são obrigatórios aqui. Casar só pelo NN marcaria como substituídas 3 dívidas de
 * 2022 da carteira TOP LIFE I por causa de acordos de 2026 da TOP LIFE 2, apagando R$ 435,00 de
 * cobrança legítima (spec §1, `cobranca-importar-chave-competencia.md`).
 *
 * ⚠️ `$nn` nem sempre é um Nosso Número: dívida antiga que nunca foi boletada entra com a referência
 * substituta `SNN:<vencimento ISO>` (`ReferenciaSubstituta`, spec
 * `cobranca-divida-sem-numero-de-boleto.md`). O papel do campo — chave de dedup — é o mesmo.
 */
final class ContaOriginalImportavel
{
    public function __construct(
        public readonly string $nn,
        public readonly string $classe,
        public readonly string $competencia,
        public readonly \DateTimeImmutable $vencimento,
        public readonly int $valorCentavos,
        /**
         * Número EXTERNO do acordo de que esta conta é parcela, quando a coluna F ("Detalhamento") o
         * declara — `Acordo 163 - Parcela 4/12` vira `163`. Nulo quando a conta é dívida original.
         *
         * É a **prova documental** que autoriza a substituição de parcela por acordo novo (spec
         * `cobranca-acordo-assume-parcelas-do-anterior.md`): sem ela a INV-I recusa, porque marcar a
         * parcela de outro acordo às cegas duplicaria a dívida no dia do rompimento. Nunca é chave de
         * busca — o casamento continua sendo NN + competência; isto só CONFIRMA o vínculo que o sistema
         * já tem.
         */
        public readonly ?int $acordoOrigemDeclarado = null,
        /** A parcela declarada na coluna F ("4/12") — só para o relatório dizer qual era. */
        public readonly ?string $parcelaOrigemDeclarada = null,
    ) {
        CompetenciaImportada::exigirValida($competencia, $nn);
    }

    /**
     * Descrição da Obrigação reconstruída (§3.2.1), no MESMO formato que o importador de inadimplência
     * gera (`BoletoImportavel::descricao()`) — uma conta reconstruída não pode parecer de outra espécie
     * na tela. A marcação de procedência é acrescentada pelo UseCase, que é quem conhece a emissão.
     */
    public function descricao(): string
    {
        return trim("{$this->classe} — competência {$this->competencia}", ' —');
    }
}
