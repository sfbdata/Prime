<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L2b da aba Documentos: o painel de detalhes com os metadados reais do #pexDados
 * (`enviadoPor`, `modificadoEm`, `paginas` — ExploradorDeDocumentosOutput) e a data
 * "Modificado" da coluna/classificação (desenho 02 - EXPEDIENTES 1.2.3, `pProps` L4953 e
 * `COLS.data` L3080).
 *
 * Teste de FOLHA (precedente: PastaExploradorLimpezaTest): sem harness de navegador, trava o
 * texto do contrato — a ordem do desenho, a linha omitida quando o campo é NULL, a soma de
 * páginas só com todos contados e o `modificadoEm ?? carregadoEm` da coluna.
 */
final class PastaExploradorPainelDetalhesTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-explorador.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** O corpo de `function $nome(…) {` até o fechamento no mesmo recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('"Modificado" é modificadoEm ?? carregadoEm: NULL = nunca editado, a última mudança é o upload')]
    public function testDataDeModificacaoCaiNoUpload(): void
    {
        self::assertStringContainsString(
            'function dataModificacao(a) { return a.modificadoEm || a.carregadoEm; }',
            $this->js(),
        );
    }

    #[TestDox('coluna, Conteúdo e classificação "Modificado" usam dataModificacao, não carregadoEm cru')]
    public function testColunaEClassificacaoUsamModificacao(): void
    {
        $js = $this->js();
        self::assertStringContainsString("data: 'Modificado' }", $js, 'rótulo da coluna (dc COLS.data L3080)');

        $linha = $this->funcao('linhaArquivo');
        self::assertStringContainsString("text: formatarData(dataModificacao(a)) })", $linha);
        self::assertStringNotContainsString('formatarData(a.carregadoEm)', $linha, 'a coluna não pode voltar a mostrar só o upload');

        $cmp = $this->funcao('comparador');
        self::assertStringContainsString(
            "case 'data':      f = function (a, b) { return String(dataModificacao(a) || '').localeCompare(String(dataModificacao(b) || '')) || cmpNome(a, b); }; break;",
            $cmp,
        );
        self::assertStringNotContainsString('carregadoEm', $cmp);

        // O item da lista leva o campo, senão o comparador leria undefined e cairia sempre no upload.
        self::assertStringContainsString('carregadoEm: a.carregadoEm, modificadoEm: a.modificadoEm,', $this->funcao('itensVisiveis'));
    }

    #[TestDox('painel de um arquivo: Tipo, Categoria, Tamanho, Modificado na ordem do desenho; depois os do sistema')]
    public function testOrdemDoDesenhoNoPainel(): void
    {
        $f = $this->funcao('propriedadesDe');
        $ordem = ["['Tipo', tipoDe(d.nome)[2]]", "['Categoria',", "['Tamanho',", "['Modificado',", "['Adicionado em',", "['Páginas',", "['Enviado por',", "['Número',", "['Local',"];
        // A busca segue de onde a anterior parou: o ramo da pasta (Tipo/Conteúdo/Local) vem antes.
        $pos = 0;
        foreach ($ordem as $rotulo) {
            $p = strpos($f, $rotulo, $pos);
            self::assertNotFalse($p, "{$rotulo} sumiu do painel ou está fora da ordem");
            $pos = $p + 1;
        }
        self::assertStringNotContainsString("'Modificado em'", $f, 'o desenho rotula "Modificado"');
    }

    #[TestDox('campo NULL no #pexDados não vira linha: nada inventado (enviadoPor, paginas, modificadoEm)')]
    public function testLinhaOmitidaQuandoNull(): void
    {
        $f = $this->funcao('propriedadesDe');
        self::assertStringContainsString("['Modificado', formatarDataHora(dataModificacao(d))],", $f);
        self::assertStringContainsString("['Adicionado em', d.modificadoEm ? formatarDataHora(d.carregadoEm) : ''],", $f, 'sem edição o Modificado já é o upload: não repete');
        self::assertStringContainsString("['Páginas', d.paginas != null ? formatarInteiro(d.paginas) : ''],", $f, '0 páginas é dado; só NULL some');
        self::assertStringContainsString("['Enviado por', d.enviadoPor || ''],", $f);
        // O filtro final é quem tira a linha: valor vazio = linha omitida.
        self::assertStringContainsString("].filter(function (p) { return p[1] !== ''; });", $f);
    }

    #[TestDox('vários itens: soma de Páginas só quando TODOS os selecionados são arquivos com páginas contadas')]
    public function testSomaDePaginasSoComTodos(): void
    {
        $soma = $this->funcao('somaDasPaginas');
        self::assertStringContainsString(
            'sel.every(function (it) { return ehArquivo(it) && it.dado.paginas != null; })',
            $soma,
        );
        self::assertStringContainsString(': null;', $soma, 'um sem contagem → sem linha, nunca soma parcial');

        $sel = $this->funcao('propriedadesDaSelecao');
        self::assertStringContainsString("if (paginas !== null) props.push(['Páginas', formatarInteiro(paginas)]);", $sel);
        $p = strpos($sel, "['Pastas',");
        $a = strpos($sel, "['Arquivos',");
        $t = strpos($sel, "['Tamanho dos arquivos',");
        self::assertTrue($p !== false && $p < $a && $a < $t, 'resumo do desenho: Pastas, Arquivos, Tamanho dos arquivos');
    }

    #[TestDox('painel monta as linhas por textContent: zero innerHTML no JS do explorador')]
    public function testSemInnerHtml(): void
    {
        self::assertStringNotContainsString('innerHTML', $this->js());
        self::assertStringContainsString("[h('dt', { text: p[0] }), h('dd', { text: p[1] })]", $this->funcao('renderizarPainel'));
    }
}
