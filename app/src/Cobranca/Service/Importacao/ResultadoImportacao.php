<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado CLARO de uma importação (SPEC §21): o que foi criado, atualizado, rejeitado e ignorado.
 * Serve tanto ao preview (dry-run: o que ACONTECERIA) quanto à confirmação (o que aconteceu). Contagens
 * de objetos/pessoas/casos novos ajudam o operador a conferir antes de confirmar.
 */
final class ResultadoImportacao
{
    /**
     * @param list<string>         $obrigacoesCriadas     NNs de boletos que viram Obrigação nova
     * @param list<string>         $obrigacoesAtualizadas NNs de boletos já existentes (reimportação idempotente)
     * @param list<LinhaRejeitada> $rejeitadas
     * @param list<string>         $sacadosDivergentes       objetos onde o Sacado do relatório difere da pessoa cobrada atual
     * @param list<string>         $referenciasReutilizadas  NNs que já existiam no caso com OUTRA competência — dívida
     *                                                       diferente com o mesmo número, nasceu separada
     * @param list<string>         $vencimentosAlterados     NNs cuja dívida é a mesma (competência igual) mas o relatório
     *                                                       trouxe vencimento novo — boleto reemitido
     * @param int                  $centavosSemBoleto        principal + juros + multa + correção das obrigações que
     *                                                       entraram por chave substituta (criadas E atualizadas).
     *                                                       Honorário fora DESTA soma; a justificativa era a
     *                                                       INV-E2, hoje revogada. Pendência nomeada em
     *                                                       `ImportarRelatorioCarteiraUseCase::centavosSemBoletoDoBoleto`
     */
    public function __construct(
        public readonly array $obrigacoesCriadas,
        public readonly array $obrigacoesAtualizadas,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $objetosCriados,
        public readonly int $pessoasCriadas,
        public readonly int $casosCriados,
        public readonly array $sacadosDivergentes,
        public readonly array $referenciasReutilizadas = [],
        public readonly array $vencimentosAlterados = [],
        public readonly int $centavosSemBoleto = 0,
    ) {
    }

    /**
     * Houve NN reutilizado ou vencimento alterado? São os dois avisos que a spec da chave
     * (`cobranca-importar-chave-competencia.md`) exige mostrar: o defeito que ela corrige era justamente
     * o silêncio nesses casos.
     */
    public function temAvisos(): bool
    {
        return $this->referenciasReutilizadas !== [] || $this->vencimentosAlterados !== [];
    }

    public function totalImportadas(): int
    {
        return count($this->obrigacoesCriadas);
    }

    /**
     * Quantas das obrigações CRIADAS são dívida que nunca teve boleto — entraram pela referência
     * substituta, não por Nosso Número (spec `cobranca-divida-sem-numero-de-boleto.md`).
     *
     * Precisa aparecer no relatório porque a distinção não é cosmética: uma é dívida boletada, com
     * número que o condômino consegue conferir no extrato; a outra é lançamento antigo que a
     * contabilidade nunca cobrou. Quem for negociar precisa saber qual é qual.
     */
    public function totalSemBoleto(): int
    {
        return $this->contarSemBoleto($this->obrigacoesCriadas);
    }

    /**
     * Idem, entre as ATUALIZADAS. Sem este contador a dívida sem boleto sumia do relatório a partir da
     * segunda importação — justamente nas rodadas de rotina, que são todas menos a primeira.
     */
    public function totalSemBoletoAtualizadas(): int
    {
        return $this->contarSemBoleto($this->obrigacoesAtualizadas);
    }

    /** @param list<string> $referencias */
    private function contarSemBoleto(array $referencias): int
    {
        return count(array_filter(
            $referencias,
            static fn (string $referencia): bool => ReferenciaSubstituta::ehSubstituta($referencia),
        ));
    }

    public function totalAtualizadas(): int
    {
        return count($this->obrigacoesAtualizadas);
    }

    public function totalRejeitadas(): int
    {
        return count($this->rejeitadas);
    }
}
