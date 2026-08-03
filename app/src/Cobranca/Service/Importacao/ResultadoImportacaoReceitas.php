<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado da importação de Receitas (spec `docs/specs/cobranca-importar-receitas.md`). A PRÉVIA e a
 * CONFIRMAÇÃO produzem esta MESMA estrutura — é isso que permite conferir a projeção contra o efeito,
 * campo a campo, e não por amostra.
 *
 * ⚠️ A prévia só é confiável se carregar ESTADO INTRA-EXECUÇÃO: dois recebimentos do mesmo objeto não
 * podem contar "objeto criado" duas vezes, e o segundo recebimento do mesmo NN tem de ver o primeiro.
 * Uma prévia que só consulta o banco mente — já mentiu duas vezes nesta frente (ver
 * `feedback_previa_precisa_de_estado`).
 */
final class ResultadoImportacaoReceitas
{
    /**
     * @param list<string>         $pagamentosCriados      NN de cada recebimento que entra
     * @param list<string>         $jaImportados           NN já presente com a mesma data (idempotência)
     * @param list<string>         $obrigacoesCriadas      NN cuja obrigação NÃO existia e nasceu paga (R1)
     * @param list<string>         $obrigacoesExistentes   NN que pousou em obrigação já conhecida
     * @param list<LinhaRejeitada> $rejeitadas
     */
    public function __construct(
        public readonly array $pagamentosCriados,
        public readonly array $jaImportados,
        public readonly array $obrigacoesCriadas,
        public readonly array $obrigacoesExistentes,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $emAberto,
        public readonly int $objetosCriados,
        public readonly int $pessoasCriadas,
        public readonly int $casosCriados,
        public readonly int $acordosCriados,
        public readonly int $totalRecebidoCentavos,
        public readonly int $honorariosCentavos,
    ) {
    }

    /** O que abate dívida, em centavos — o bruto menos a parte que é honorário do escritório. */
    public function recuperadoDividaCentavos(): int
    {
        return $this->totalRecebidoCentavos - $this->honorariosCentavos;
    }
}
