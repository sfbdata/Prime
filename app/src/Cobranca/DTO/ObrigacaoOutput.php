<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;

/**
 * Leitura de uma Obrigação para a seção "Dívida em aberto" da página do objeto (Etapa 8; a partir do
 * ajuste 10, essa seção fundiu as antigas abas Obrigações e Acordos numa fila única). Dinheiro em
 * centavos int (formatado no Twig com `|centavos`); `valorAtual` = `valorExigivel()` = original +
 * juros + multa + correção + honorários (INV-E2 revogada por `cobranca-honorario-no-total.md`)
 * (SPEC §10).
 * Sinaliza (vigente-aware) se a obrigação foi substituída por acordo vigente (sai do saldo, invariável
 * 15), se é parcela de acordo vigente, ou se é parcela de acordo rompido/cancelado (`parcelaDeAcordoDesfeito`
 * — histórico, fora do saldo) — para o Twig marcar visualmente e liberar/travar a edição sem reimplementar a regra.
 */
final class ObrigacaoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $descricao,
        public readonly int $valorOriginal,
        public readonly int $encargosReconhecidos,
        public readonly int $valorAtual,
        public readonly \DateTimeImmutable $vencimentoOriginal,
        public readonly ?string $referenciaExterna,
        public readonly bool $substituidaPorAcordo,
        public readonly bool $ehParcelaAcordo,
        public readonly bool $parcelaDeAcordoDesfeito,
        /** Acordo que gerou esta obrigação (null = não é parcela) — agrupa os grupos de acordo dentro da
         * seção "Dívida em aberto" (Ajuste 8; grupos consolidados no Ajuste 10). */
        public readonly ?int $acordoOrigemId = null,
        /** Acordo que substituiu esta obrigação (null = não substituída) — agrupa as trocadas (Ajuste 10). */
        public readonly ?int $acordoSubstitutoId = null,
        /**
         * Σ das alocações de pagamento nesta obrigação (centavos) — DERIVADO (invariável 20), nunca coluna.
         * Carregado em LOTE pelo UseCase (`somasPorObrigacaoDosCasos`); default 0 mantém os chamadores antigos.
         */
        public readonly int $alocado = 0,
        /**
         * Valor BRUTO a cobrar para quitar o `restante` desta obrigação (centavos) — já com os honorários
         * acrescidos quando a forma é `acrescido_divida` (Ajuste 10, spec §5.1). É o prefill do "Receber":
         * o alvo é invisível ao gestor (quitar R$1.200 exige digitar R$1.320). Calculado no SERVIDOR, pelo
         * UseCase, que é quem conhece o snapshot de honorários do caso — o DTO continua burro.
         */
        public readonly int $brutoSugerido = 0,
        /**
         * Encargos SEPARADOS materializados (centavos) — as colunas do relatório da contabilidade
         * (spec "encargos configuráveis em cascata" §11). `encargosReconhecidos` acima continua
         * sendo a soma de juros+multa+correcao (INV-E1); os honorários ENTRAM no `valorAtual` desde a
         * spec `cobranca-honorario-no-total.md` (INV-E2 revogada) e seguem aparecendo detalhados na
         * linha. Defaults 0 preservam os chamadores antigos.
         */
        public readonly int $juros = 0,
        public readonly int $multa = 0,
        public readonly int $correcao = 0,
        public readonly int $honorarios = 0,
        /**
         * Taxa por-obrigação (FIX crítico, Task 9): as quatro colunas CRUAS de override (bp; `null` =
         * herda a cascata Carteira→Caso→Obrigação) — NÃO o valor calculado (`juros`/`multa`/... acima).
         * A linha (`_divida.html.twig`) publica isto via `data-taxa-*-bp` para o modal de "Editar"
         * reidratar o override ATUAL ao abrir; sem isso, reabrir "Editar" nasce sempre "herda" e
         * qualquer submissão (mesmo só corrigir a descrição) apaga silenciosamente o override existente
         * (`EditarObrigacaoUseCase` sempre deriva os 4 overrides do que o Form submete). Defaults `null`
         * preservam os chamadores antigos.
         */
        public readonly ?int $taxaJurosMensalBp = null,
        public readonly ?int $taxaMultaBp = null,
        public readonly ?int $taxaCorrecaoBp = null,
        public readonly ?int $taxaHonorariosBp = null,
        /**
         * Base de incidência RESOLVIDA (cascata) da multa e dos honorários — Principal (só o valor original)
         * ou Composta (valor + juros + multa + correção). A tela usa isto para exibir o "%" de cada encargo
         * sobre a base CERTA e rotular corretamente: com base configurável, assumir composta fixa mostraria
         * um percentual e uma nota factualmente errados para quem opera na base Principal (achado da auditoria).
         * Defaults = os do domínio (multa Principal, honorários Composta) para os chamadores antigos.
         */
        public readonly BaseEncargo $baseMulta = BaseEncargo::Principal,
        public readonly BaseEncargo $baseHonorarios = BaseEncargo::Composta,
        /**
         * Taxa de juros de mora EFETIVA (bp) que a cascata Carteira→Caso→Obrigação realmente aplica —
         * resolvida no `ConfigEncargos`, NÃO o override cru `taxaJurosMensalBp` acima (que é `null`
         * quando a obrigação herda). É o que o card usa para rotular o juros pela TAXA configurada
         * ("1% a.m. pró-rata") em vez do percentual que o valor pró-rata representa sobre o principal —
         * este último cresce a cada dia e engana quem lê. `null` (sem config resolvida) = não exibe rótulo.
         */
        public readonly ?int $taxaJurosEfetivaBp = null,
        /** Preenchido = encargos congelados, param de crescer (INV-E4); a UI marca isso. */
        public readonly ?\DateTimeImmutable $encargosCongeladosEm = null,
        /** Data de referência da última materialização — o "atualizado em" da tela. */
        public readonly ?\DateTimeImmutable $encargosAtualizadosEm = null,
        /**
         * Data do ÚLTIMO pagamento alocado nesta obrigação — o "Pago em" da seção "Já pago" da aba
         * Dívida (R5). Vem de `AlocacaoPagamentoRepository::ultimoPagamentoPorObrigacaoDosCasos`, em
         * lote, e é `null` em toda obrigação que nunca recebeu alocação.
         *
         * ⚠️ Preenchido NÃO significa quitada: quem responde "está paga?" é `quitada()` (alocado ≥
         * exigível), a mesma régua do chip "Paga" da linha. Uma obrigação PARCIALMENTE paga tem data
         * e continua em aberto. E o inverso também existe: uma obrigação de exigível ZERO satisfaz
         * `quitada()` (0 ≥ 0) sem nunca ter recebido alocação, e cai na seção "Já pago" sem data —
         * por isso a coluna tolera o vazio. (Liquidação não entra nesta conta: ela é do CASO, abate
         * no saldo por `CalculadoraSaldo` e não cria alocação nenhuma na obrigação.)
         *
         * A seção "Já pago" particiona por `quitada()` e usa esta data só para EXIBIR — nunca para
         * decidir de que lado a linha cai.
         */
        public readonly ?\DateTimeImmutable $pagoEm = null,
        /**
         * Os encargos NUNCA foram calculados (acordo substituto sem data) — distinto de "calculados e
         * deram R$ 0,00". Espelha `Obrigacao::encargosNaoCalculados()`; a tela mostra "—" com o motivo
         * NA LINHA, nunca um número. Default `false` mantém os chamadores que não passam o sinal.
         */
        public readonly bool $encargosNaoCalculados = false,
    ) {
    }

    /*
     * `totalComHonorarios()` foi REMOVIDO (spec `cobranca-honorario-no-total.md` §3). Ele somava
     * `valorAtual + honorarios`; agora o honorário já está DENTRO de `valorAtual`, e mantê-lo
     * contaria o honorário DUAS VEZES na coluna "Total" da tela — em silêncio, porque o número
     * continuaria plausível. Quem exibe o total da linha usa `valorAtual`, e o valor mostrado é
     * exatamente o mesmo de antes.
     */

    /**
     * Quanto ainda falta receber nesta obrigação (centavos), com PISO 0: alocação manual não tem teto por
     * obrigação, então uma super-alocada devolveria negativo e poluiria a tela (spec §10, ajuste 10).
     */
    public function restante(): int
    {
        return max(0, $this->valorAtual - $this->alocado);
    }

    /**
     * Coluna `Acréscimos` da linha da dívida (redesenho 1a): tudo que foi somado ao valor de face —
     * juros + multa + correção + honorários.
     *
     * Vive AQUI e não no Twig porque é dinheiro (a mesma regra dos totais do `MontarDetalheCasoUseCase`):
     * aqui há teste. E é a identidade que faz as três colunas da linha fecharem a olho:
     * `valorOriginal + acrescimos() === valorAtual`, porque `valorAtual` é exatamente
     * `Obrigacao::valorExigivel()` = original + juros + multa + correção + honorários.
     *
     * ⚠️ NÃO é `encargosReconhecidos`: aquele é a soma dos TRÊS encargos (INV-E1), sem o honorário — o
     * honorário entrou no exigível pela spec `cobranca-honorario-no-total.md` e, se ficasse de fora
     * aqui, a coluna `Original + Acréscimos` não bateria com o `Total` ao lado.
     */
    public function acrescimos(): int
    {
        return $this->juros + $this->multa + $this->correcao + $this->honorarios;
    }

    /** Alocado cobre o exigível — espelha `ParcelaAcordoResumoOutput::quitada`. */
    public function quitada(): bool
    {
        return $this->alocado >= $this->valorAtual;
    }

    public static function fromEntity(
        Obrigacao $o,
        int $alocado = 0,
        int $brutoSugerido = 0,
        ?ConfigEncargos $config = null,
        ?\DateTimeImmutable $pagoEm = null,
    ): self {
        $substituto = $o->getAcordoSubstituto();
        $origem = $o->getAcordoOrigem();

        return new self(
            id: $o->getId() ?? 0,
            descricao: $o->getDescricao(),
            valorOriginal: $o->getValorOriginal(),
            encargosReconhecidos: $o->getEncargosReconhecidos(),
            // A fórmula do exigível mora na ENTIDADE (INV-E1). Aqui era replicada — e replicar
            // fórmula de dinheiro é como as duas versões divergem sem ninguém notar.
            valorAtual: $o->valorExigivel(),
            vencimentoOriginal: $o->getVencimentoOriginal(),
            referenciaExterna: $o->getReferenciaExterna(),
            // Vigente-aware: só marca/trava quando o acordo está ATIVO/CUMPRIDO. Acordo rompido/cancelado
            // solta a original (volta ao saldo) e vira a parcela em histórico (`parcelaDeAcordoDesfeito`).
            substituidaPorAcordo: $substituto !== null && $substituto->getStatus()->ehVigente(),
            ehParcelaAcordo: $origem !== null && $origem->getStatus()->ehVigente(),
            parcelaDeAcordoDesfeito: $origem !== null && !$origem->getStatus()->ehVigente(),
            acordoOrigemId: $origem?->getId(),
            acordoSubstitutoId: $substituto?->getId(),
            alocado: $alocado,
            brutoSugerido: $brutoSugerido,
            juros: $o->getJuros(),
            multa: $o->getMulta(),
            correcao: $o->getCorrecao(),
            honorarios: $o->getHonorarios(),
            // Override CRU (bp), não o calculado acima — ver doc do construtor (FIX crítico Task 9).
            taxaJurosMensalBp: $o->getTaxaJurosMensalBp(),
            taxaMultaBp: $o->getTaxaMultaBp(),
            taxaCorrecaoBp: $o->getTaxaCorrecaoBp(),
            taxaHonorariosBp: $o->getTaxaHonorariosBp(),
            // Base resolvida da cascata (Obrigação → Caso → Carteira). Sem a config resolvida, cai nos
            // defaults do domínio — não inventa base, e mantém os chamadores antigos intactos.
            baseMulta: $config?->baseMulta ?? BaseEncargo::Principal,
            baseHonorarios: $config?->baseHonorarios ?? BaseEncargo::Composta,
            // Taxa de juros EFETIVA resolvida (bp) — só existe quando a config da cascata é passada.
            // Sem ela, `null`: o card não inventa um rótulo de taxa.
            taxaJurosEfetivaBp: $config?->taxaJurosMensalBp,
            encargosCongeladosEm: $o->getEncargosCongeladosEm(),
            encargosAtualizadosEm: $o->getEncargosAtualizadosEm(),
            // Vem de fora (mapa em lote do repositório) e não da entidade de propósito: ler daqui
            // custaria uma consulta por obrigação — N+1 na aba mais aberta do módulo.
            pagoEm: $pagoEm,
            encargosNaoCalculados: $o->encargosNaoCalculados(),
        );
    }
}
