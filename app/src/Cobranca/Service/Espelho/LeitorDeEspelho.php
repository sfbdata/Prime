<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\TipoRelatorioContabil;

/**
 * O contrato de um leitor do espelho — um por layout de relatório
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §4.1).
 *
 * Existe porque os quatro relatórios da contabilidade **não se parecem**: a inadimplência tem uma aba
 * e 15 colunas com encargo; os acordos têm uma aba POR ACORDO e duas tabelas, nenhuma com encargo; as
 * receitas têm 10 colunas de pagamento; o cadastro não tem dinheiro nenhum. Um leitor só com `if` por
 * tipo viraria o lugar onde os quatro layouts se contaminam.
 *
 * INV-Q3 (herda o INV-R1): **nenhum leitor reaproveita adapter de importação.** Um leitor que herdasse
 * a interpretação do adapter não serviria para conferir o adapter, que é para o que ele existe.
 *
 * INV-Q4 (herda o INV-R2): **burro quanto ao significado, não quanto à estrutura.** O leitor não
 * decide o que é principal, encargo ou pagamento. Guarda o que está escrito.
 */
interface LeitorDeEspelho
{
    /** Qual dos quatro relatórios este leitor sabe ler. */
    public function tipo(): TipoRelatorioContabil;

    /**
     * A versão da LEITURA deste layout — entra na chave de idempotência do lote (SPEC espelho §4.2).
     *
     * ⚠️ Cada leitor versiona a si mesmo. Corrigir a leitura dos acordos não pode invalidar os lotes
     * de inadimplência já carregados: seriam relidos sem necessidade e o histórico ganharia lote novo
     * idêntico ao anterior.
     */
    public function versao(): int;

    /**
     * @throws ArquivoForaDoLayoutException quando o arquivo não é deste layout
     */
    public function ler(string $caminhoArquivo): ArquivoEspelhado;

    /**
     * O PORTÃO (INV-G1): antes de gravar, o que foi lido tem que fechar com o que o próprio arquivo
     * declara. Se não fecha, o leitor está errado e nada é gravado — espelho torto é pior que espelho
     * nenhum, porque tem cara de verdade.
     *
     * 🔑 **O portão é POR LAYOUT, e não podia ser único.** A inadimplência fecha dinheiro contra a
     * linha `Total de inadimplência`; as receitas contra `Total de receitas`; os acordos **não têm
     * total geral nenhum** e fecham por aba, contra o `Valor final acordado` do cabeçalho dela; o
     * cadastro não tem dinheiro e fecha por CONTAGEM, contra `Total de unidades filtradas`.
     * Um portão único obrigaria os três layouts novos a fingir que têm um total de dinheiro que não
     * têm — e fingir aqui é gravar espelho torto.
     *
     * @throws ReconciliacaoInternaFalhouException quando as contas do arquivo não fecham entre si
     */
    public function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void;
}
