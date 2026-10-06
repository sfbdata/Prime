<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Contratos do `pasta-explorador.js` que nenhum teste de HTML vê, porque as linhas são
 * renderizadas pelo próprio JS a partir de `#pexDados`.
 *
 * Lê a FOLHA (precedente: PastaArquivosCssIntactoTest). Não há harness de navegador na suíte;
 * o que dá para travar é o TEXTO do gatilho do pré-visualizador, do link de download, do
 * comparador padrão e das guardas — o suficiente para um refactor que renomeie `data-url`
 * ficar vermelho em vez de abrir o modal vazio em produção.
 */
final class PastaExploradorContratoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-explorador.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('o nome do arquivo é o gatilho do #previewDocModal: .pex-arq-preview com data-url/nome/mime, href de visualizar e target=_blank')]
    public function testGatilhoDoPreVisualizador(): void
    {
        $js = $this->js();

        /* O visualizador-documento.js lê `relatedTarget.dataset.url|nome|mime`. O nome continua
           um <a> de verdade para a URL de visualização, em outra aba (ctrl/meio-clique e sem JS). */
        self::assertMatchesRegularExpression(
            "/h\('a', \{\s*href: a\.viewUrl, target: '_blank', rel: 'noopener noreferrer',\s*class: 'pex-nome pex-arq-preview',\s*'data-url': a\.viewUrl, 'data-nome': a\.nome, 'data-mime': a\.mime/s",
            $js,
            'o link do nome perdeu o gatilho ou um dos três data-* que o modal lê'
        );
        // "Visualizar"/"Abrir" do menu de contexto, Enter e Espaço passam pelo gatilho da linha —
        // ou por um nó avulso com os MESMOS três dados quando a linha não está na tela.
        self::assertMatchesRegularExpression(
            "/return g \|\| h\('span', \{ 'data-url': a\.viewUrl, 'data-nome': a\.nome, 'data-mime': a\.mime \|\| '' \}\);/",
            $js
        );
        // E a abertura passa o GATILHO ao modal (vira `relatedTarget`).
        self::assertStringContainsString("bootstrap.Modal.getOrCreateInstance(modal).show(gatilho);", $js);
        self::assertMatchesRegularExpression("/e\.target\.closest\('\.pex-arq-preview'\)/", $js, 'é a classe que o clique intercepta');
        // Clique simples no nome SELECIONA (DOC-14/21): com Ctrl/Cmd/Alt o navegador segue o <a>.
        self::assertStringContainsString("if (e.ctrlKey || e.metaKey || e.altKey) return;", $js);
    }

    #[TestDox('baixar continua a um clique (menu de contexto e barra de seleção), com a URL de download do JSON em outra aba')]
    public function testLinkDeDownload(): void
    {
        $js = $this->js();

        self::assertMatchesRegularExpression(
            "/h\('a', \{ href: a\.downloadUrl, target: '_blank', rel: 'noopener', hidden: true \}\);/",
            $js
        );
        self::assertStringContainsString("op('Baixar', 'bi-download', function () { baixar(a); })", $js, 'item do menu de contexto');
        self::assertStringContainsString("case 'baixar':   if (sel.length === 1 && sel[0].tipo === 'arquivo') baixar(sel[0].dado); break;", $js, 'barra: só com UM arquivo (o .zip é do L8)');
    }

    #[TestDox('nada de innerHTML: toda linha nasce por createElement/textContent (nome de arquivo é dado do usuário)')]
    public function testSemInnerHtml(): void
    {
        self::assertStringNotContainsString('innerHTML', $this->js(), 'um nome como <img onerror=…> viraria HTML executável na lista');
        self::assertStringNotContainsString('insertAdjacentHTML', $this->js());
    }

    #[TestDox('ordem padrão (Manual): `ordem` e, no empate, NOME com collator pt-BR natural — nunca id; pastas antes dos arquivos')]
    public function testComparadorPadrao(): void
    {
        $js = $this->js();

        /* Em produção 20.909 dos 20.954 documentos têm ordem=0: o desempate É a ordem que o usuário
           vê. Por id seria "ordem de upload" com cara de alfabética. */
        self::assertStringContainsString(
            "default:          f = function (a, b) { return ((a.ordem || 0) - (b.ordem || 0)) || cmpNome(a, b); };",
            $js
        );
        self::assertStringNotContainsString('(a.id - b.id)', $js, 'id não é critério de ordem visível');
        self::assertStringContainsString(
            "function cmpNome(a, b) { return String(a.nome).localeCompare(String(b.nome), 'pt-BR', { sensitivity: 'base', numeric: true }); }",
            $js,
            'collator pt-BR, sem distinguir acento/caixa, com números naturais (2 antes de 10)'
        );
        self::assertStringContainsString('return ps.concat(as);', $js, 'pastas sempre antes, em qualquer classificação');
    }

    #[TestDox('setas: pílulas do Organizar com bi-arrow-* (dc L3152), cabeçalho das colunas com bi-chevron-* (dc L4862)')]
    public function testSetasDeClassificacao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("(classificar.desc ? ' bi-arrow-down' : ' bi-arrow-up')", $js);
        self::assertStringContainsString("(classificar.desc ? ' bi-chevron-down' : ' bi-chevron-up')", $js);
    }

    #[TestDox('Backspace/Alt+setas não disparam com foco em campo de texto, select, contenteditable nem dentro do checklist')]
    public function testAtalhosNaoDisparamNoChecklistNemEmCampos(): void
    {
        $js = $this->js();

        self::assertStringContainsString("if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;", $js);
        self::assertStringContainsString("if (e.target && e.target.closest && e.target.closest('#pexChecklist')) return;", $js, 'Backspace no checklist é do checklist');
    }

    #[TestDox('a busca tem debounce de 120 ms na digitação; Esc e o ⊗ limpam na hora')]
    public function testDebounceDaBusca(): void
    {
        $js = $this->js();

        self::assertMatchesRegularExpression("/buscaTimer = setTimeout\(function \(\) \{ aplicarBusca\(el\.busca\.value\); \}, 120\);/", $js);
        self::assertMatchesRegularExpression("/e\.key === 'Escape'[^\n]*aplicarBusca\(''\);/", $js, 'Esc limpa sem esperar o timer');
        self::assertMatchesRegularExpression("/el\.buscaLimpar\.addEventListener\('click', function \(\) \{ aplicarBusca\(''\);/", $js);
    }

    #[TestDox('storage: só as preferências listadas na spec (pex:classificar, pex:colunas, pex:modo, pex:painel) e a pasta aberta por sessão; o filtro por tipo não persiste')]
    public function testChavesDeStorage(): void
    {
        $js = $this->js();

        preg_match_all("/localStorage\.setItem\(([^,]+),/", $js, $m);
        self::assertSame(
            ['CHAVE_CLASSIFICAR', 'CHAVE_COLUNAS', 'CHAVE_MODO', 'CHAVE_PAINEL'],
            array_values(array_unique($m[1])),
            'toda gravação passa por uma das quatro constantes — chave nova exige passar pela spec'
        );
        self::assertStringContainsString("const CHAVE_CLASSIFICAR = 'pex:classificar';", $js);
        self::assertStringContainsString("const CHAVE_COLUNAS     = 'pex:colunas';", $js);
        self::assertStringContainsString("const CHAVE_MODO        = 'pex:modo';", $js);
        self::assertStringContainsString("const CHAVE_PAINEL      = 'pex:painel';", $js);
        self::assertStringContainsString("const CHAVE_CAMINHO     = 'pex:pasta:' + pastaId + ':caminho';", $js);

        // Nenhuma chave literal 'pex:…' além das cinco constantes acima — e nada de filtro
        // salvo: o desenho não persiste `expTipoF`, o filtro vale só para a visita.
        preg_match_all("/'(pex:[^']*)'/", $js, $literais);
        self::assertSame(
            ['pex:pasta:', 'pex:classificar', 'pex:colunas', 'pex:modo', 'pex:painel'],
            array_values(array_unique($literais[1]))
        );
        self::assertStringNotContainsString('pex:filtroTipo', $js, 'o filtro por tipo não vai para o storage');
        self::assertSame([], preg_grep('/Storage\.(setItem|getItem)\([^)]*[Ff]iltro/', explode("\n", $js)), 'nenhuma leitura/gravação de storage do filtro');
        self::assertStringContainsString("let filtroTipo  = 'todos';", $js, 'o filtro nasce em Todos a cada visita');
        self::assertStringNotContainsString('fmTab_', $js, 'o retorno à aba é pelo fragmento #documentos');
        self::assertStringNotContainsString('fmFolder_', $js);
        self::assertStringNotContainsString('bj-docs-', $js, 'as chaves do protótipo (bj-docs-colunas…) não vêm para o sistema');
    }

    #[TestDox('storage: toda leitura e gravação de localStorage está dentro de try/catch (modo privado/bloqueado lança)')]
    public function testStorageSempreComTryCatch(): void
    {
        $linhas = preg_grep('/localStorage\./', explode("\n", $this->js()));
        self::assertNotEmpty($linhas);
        foreach ($linhas as $n => $linha) {
            if (str_starts_with(ltrim($linha), '//') || str_starts_with(ltrim($linha), '*') || str_contains($linha, 'Storage —')) {
                continue; // comentário
            }
            self::assertMatchesRegularExpression('/try \{ .*localStorage\.(getItem|setItem)\(.*\} catch \(e\)/', $linha, 'linha ' . ($n + 1) . ' acessa localStorage fora de try/catch');
        }
    }
}
