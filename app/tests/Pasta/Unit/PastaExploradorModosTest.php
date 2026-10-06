<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L2 da aba Documentos: os oito modos, as colunas móveis/redimensionáveis, o filtro por
 * tipo e o painel de detalhes — os TOKENS que vêm do desenho (02 - EXPEDIENTES 1.2.3) e que
 * nenhum teste de HTML vê, porque as linhas e o painel são montados pelo JS.
 *
 * Teste de FOLHA (precedente: PastaArquivosCssIntactoTest, PastaExploradorContratoJsTest): não
 * há harness de navegador na suíte. O que dá para travar é o número que o desenho fixou — um
 * "arredondamento" de 168 para 160 no modo extra grande, ou um hex sem par escuro, fica vermelho
 * aqui em vez de passar despercebido até o smoke.
 */
final class PastaExploradorModosTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-explorador.css';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
    }

    /** Corpo do primeiro bloco cujo seletor é exatamente $seletor. */
    private function bloco(string $seletor): string
    {
        $css = $this->css();
        $pos = strpos($css, "\n" . $seletor . ' {');
        self::assertNotFalse($pos, "bloco {$seletor} não encontrado");
        $ini = strpos($css, '{', $pos) + 1;
        $fim = strpos($css, "\n}", $ini);

        return substr($css, $ini, $fim - $ini);
    }

    /** @return list<string> */
    private function variaveis(string $bloco): array
    {
        preg_match_all('/(--pex-[a-z0-9-]+)\s*:/', $bloco, $m);

        return array_values(array_unique($m[1]));
    }

    #[TestDox('os oito modos com o tamanho de ícone do desenho (dc expVals): xg 72, g 52, m 38, p 18, lista 16, det 17, blocos 40, cont 32; Detalhes é o padrão')]
    public function testTamanhoDoIconePorModo(): void
    {
        $js = $this->js();

        self::assertStringContainsString("const ICONE_PX     = { xg: 72, g: 52, m: 38, p: 18, lista: 16, det: 17, blocos: 40, cont: 32 };", $js);
        self::assertStringContainsString("const MODOS_GRADE  = ['xg', 'g', 'm'];", $js);
        self::assertStringContainsString("const MODO_PADRAO  = 'det';", $js);
        // Preferência inválida (ou de outra versão) cai no padrão, nunca num modo inexistente.
        self::assertStringContainsString('return v && Object.prototype.hasOwnProperty.call(ICONE_PX, v) ? v : MODO_PADRAO;', $js);

        $css = $this->css();
        foreach (['xg' => 72, 'g' => 52, 'm' => 38, 'p' => 18, 'lista' => 16, 'det' => 17, 'blocos' => 40, 'cont' => 32] as $modo => $px) {
            self::assertMatchesRegularExpression('/\.pex--m-' . $modo . '\s+\{ --pex-ico: ' . $px . 'px; \}/', $css, "o CSS do modo {$modo} tem de usar {$px}px");
        }
    }

    #[TestDox('contêiner por modo (dc wrapSt): grade 168/128/104, Pequenos 210, Lista em colunas de 220 com ⌈n/3⌉ linhas, Blocos 270, Conteúdo com borda inferior')]
    public function testConteinerDeCadaModo(): void
    {
        $css = $this->css();

        self::assertStringContainsString('.pex--m-xg .pex-lista     { grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); }', $css);
        self::assertStringContainsString('.pex--m-g .pex-lista      { grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); }', $css);
        self::assertStringContainsString('.pex--m-m .pex-lista      { grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); }', $css);
        self::assertMatchesRegularExpression('/\.pex--m-p \.pex-lista\s+\{[^}]*minmax\(min\(210px, 100%\), 1fr\)[^}]*gap: 2px 8px/', $css);
        self::assertMatchesRegularExpression('/\.pex--m-lista \.pex-lista\s+\{[^}]*grid-auto-flow: column;[^}]*repeat\(var\(--pex-linhas, 1\), auto\)[^}]*minmax\(220px, 1fr\)/', $css);
        self::assertMatchesRegularExpression('/\.pex--m-blocos \.pex-lista \{[^}]*minmax\(min\(270px, 100%\), 1fr\)/', $css);
        self::assertMatchesRegularExpression('/\.pex--m-cont \.pex-item\s+\{[^}]*padding: 10px 12px;[^}]*border-bottom: 1px solid var\(--pex-cont-bd\)/', $css);
        // Nome em até duas linhas nos modos de grade.
        self::assertMatchesRegularExpression('/\.pex--grade \.pex-nome \{[^}]*-webkit-line-clamp: 2;[^}]*overflow-wrap: anywhere;/', $css);

        // ⌈n/3⌉ linhas no modo Lista.
        self::assertStringContainsString("el.lista.style.setProperty('--pex-linhas', String(Math.max(1, Math.ceil(itens.length / 3))));", $this->js());
    }

    #[TestDox('ícone "estilo Office" só a partir de 52px (dc fi): folha + contorno + selo; siglas e cores do FI, uma letra para Word/Excel/PowerPoint')]
    public function testIconeEstiloOffice(): void
    {
        $js = $this->js();

        self::assertStringContainsString('const OFFICE_MIN_PX = 52;', $js);
        self::assertStringContainsString('if (px < OFFICE_MIN_PX) return icone(t[0] + \' pex-ico-\' + t[1]);', $js, 'abaixo de 52px fica o ícone pequeno do tipo');
        self::assertStringContainsString("PDF: ['pdf', 'PDF'], DOCX: ['word', 'W'], DOC: ['word', 'W'], XLSX: ['excel', 'X'], XLS: ['excel', 'X'], CSV: ['excel', 'X'],", $js);
        self::assertStringContainsString("PPTX: ['ppt', 'P'], ZIP: ['zip', 'ZIP'], RAR: ['rar', 'RAR'], PNG: ['img', 'PNG'], JPG: ['img', 'JPG'], JPEG: ['img', 'JPG'],", $js);
        self::assertStringContainsString("const f = FI[ext] || ['outro', ext.slice(0, 3) || '?'];", $js, 'fora do mapa: cinza com as 3 primeiras letras, como o desenho');

        $claro = $this->bloco('.pex');
        foreach (['pdf' => '#d93025', 'word' => '#185abd', 'excel' => '#107c41', 'ppt' => '#c43e1c', 'zip' => '#c99a06', 'rar' => '#7d3c98', 'img' => '#0f7b8c', 'txt' => '#5f7684', 'mp4' => '#6b3fa0', 'outro' => '#6b8494'] as $tipo => $hex) {
            self::assertMatchesRegularExpression('/--pex-fi-' . $tipo . ':\s+' . preg_quote($hex, '/') . ';/', $claro, "cor do selo {$tipo} do desenho");
        }
        $css = $this->css();
        // Proporções do fi(): folha deslocada 0,21; selo 0,74×0,36 com sigla 0,22; uma letra 0,5×0,5 com 0,34.
        self::assertStringContainsString('left: calc(var(--s) * .21);', $css);
        self::assertMatchesRegularExpression('/\.pex-fi-selo \{[^}]*min-width: calc\(var\(--s\) \* \.74\);[^}]*height: calc\(var\(--s\) \* \.36\);[^}]*font-size: max\(6px, calc\(var\(--s\) \* \.22\)\);/', $css);
        self::assertStringContainsString('.pex-fi--1 .pex-fi-selo { min-width: calc(var(--s) * .5); height: calc(var(--s) * .5); font-size: calc(var(--s) * .34); letter-spacing: 0; }', $css);
    }

    #[TestDox('colunas: padrão e limites da alça do desenho (tipo 80–360, tamanho 60–200, data 80–240); a grade vai numa variável CSS')]
    public function testColunasMoveisERedimensionaveis(): void
    {
        $js = $this->js();

        self::assertStringContainsString("const COLUNAS_PAD = { ord: ['nome', 'tipo', 'cat', 'tam', 'data'], w: { tipo: 150, cat: 140, tam: 90, data: 110 } };", $js);
        self::assertStringContainsString('const COLUNAS_LIM = { tipo: [80, 360], cat: [80, 300], tam: [60, 200], data: [80, 240] };', $js);
        // Nome flexível com mínimo de 140px. Sem coluna de ⋮ na grade do JS (DOC-20/S-1): ela é
        // do CSS e só existe em `(hover: none)` — ver PastaExploradorInteracaoTest.
        self::assertStringContainsString("return k === 'nome' ? 'minmax(140px, 1fr)' : colunas.w[k] + 'px'; }).join(' ');", $js);
        self::assertStringNotContainsString("+ ' 32px'", $js);
        self::assertStringContainsString("raiz.style.setProperty('--pex-gtc', gradeDasColunas());", $js);
        // Preferência corrompida não entra: ordem só se tiver exatamente as colunas conhecidas.
        self::assertStringContainsString('if (Array.isArray(v.ord) && v.ord.length === c.ord.length && c.ord.every(function (k) { return v.ord.indexOf(k) !== -1; })) c.ord = v.ord.slice();', $js);
        self::assertStringContainsString('if (isFinite(n) && n > 0) c.w[k] = limitarLargura(k, n);', $js);
        // Arraste do título só depois de 6px (clique continua classificando).
        self::assertStringContainsString('if (!ativo && Math.abs(ev.clientX - x0) < 6) return;', $js);

        $css = $this->css();
        self::assertStringContainsString('grid-template-columns: var(--pex-gtc, minmax(140px, 1fr) 150px 90px 110px); gap: 12px;', $css, 'a grade do desenho: Nome 1fr · Tipo 150 · Tamanho 90 · Modificado 110 — sem ⋮');
        self::assertMatchesRegularExpression('/\.pex-alca \{[^}]*right: -9px;[^}]*width: 7px;[^}]*cursor: col-resize;/', $css, 'alça de 7px (dc L2214)');
        self::assertStringContainsString('.pex-col.pex-col--ins-esq { box-shadow: -7px 0 0 -5px var(--pex-col-ins); }', $css);
        self::assertStringContainsString('.pex-col.pex-col--ins-dir { box-shadow: 7px 0 0 -5px var(--pex-col-ins); }', $css);
    }

    #[TestDox('filtro por tipo (dc TIPOS_DOC/expGrupo): os 8 grupos e as extensões de cada um; a contagem é do conjunto ANTES do filtro')]
    public function testFiltroPorTipo(): void
    {
        $js = $this->js();

        self::assertStringContainsString("const FILTROS = ['todos', 'pastas', 'pdf', 'word', 'excel', 'img', 'zip', 'outros'];", $js);
        self::assertStringContainsString("if (/^DOCX?$|^RTF$|^ODT$/.test(e)) return 'word';", $js);
        self::assertStringContainsString("if (/^XLSX?$|^CSV$|^ODS$/.test(e)) return 'excel';", $js);
        self::assertStringContainsString("if (/^(JPE?G|PNG|GIF|WEBP|HEIC|BMP)$/.test(e)) return 'img';", $js);
        self::assertStringContainsString("if (/^(ZIP|RAR|7Z)$/.test(e)) return 'zip';", $js);
        self::assertStringContainsString('if (FILTROS.indexOf(id) === -1) return;', $js, 'grupo desconhecido não vira filtro');

        // A contagem é calculada antes de filtrar (senão todo grupo não escolhido viraria 0 e
        // ficaria desabilitado — o filtro se trancaria).
        $posContagem = strpos($js, 'contagemTipos[g] = (contagemTipos[g] || 0) + 1;');
        $posFiltro   = strpos($js, 'ps = ps.filter(doTipo);');
        self::assertNotFalse($posContagem);
        self::assertNotFalse($posFiltro);
        self::assertLessThan($posFiltro, $posContagem);
        // Zero desabilita, menos "Todos" e o próprio filtro ativo (pasta sem nada do tipo, ao subir pela trilha).
        self::assertStringContainsString("b.disabled = n === 0 && id !== 'todos' && !on;", $js);
    }

    #[TestDox('reordenar (Sortable) só com o nível INTEIRO na tela: desligado com busca ou filtro por tipo — senão o /reordenar grava ordem parcial')]
    public function testSortableDesligadoComBuscaOuFiltro(): void
    {
        $js = $this->js();

        /* Sem a condição do filtro, o onEnd manda só os ids visíveis e o ReordenarDocumentosUseCase
           grava uma ordem parcial: a ordem Manual dos escondidos se perde. */
        self::assertStringContainsString(
            "return !!window.Sortable && classificar.chave === 'manual' && normalizar(busca) === '' && filtroTipo === 'todos';",
            $js
        );
        self::assertStringContainsString("if (!usaSortable()) return;", $js, 'ligarSortable obedece a mesma guarda');
        // Sem Sortable, o arraste nativo (soltar em pasta) tem de continuar: o draggable das linhas
        // usa a MESMA condição — nas duas linhas (pasta e arquivo), sem sobrar a versão antiga.
        self::assertSame(2, substr_count($js, "draggable: usaSortable() ? null : 'true',"));
        self::assertStringNotContainsString("draggable: classificar.chave === 'manual' ? null : 'true'", $js);
        self::assertSame(1, substr_count($js, 'sortable = new Sortable('), 'um único ponto cria o Sortable');
    }

    #[TestDox('toque (< 768px ou hover:none): tocar em pasta ENTRA e tocar no nome do arquivo ABRE; no mouse o clique simples só seleciona (L5)')]
    public function testToqueEntraNaPastaEOCliqueSeleciona(): void
    {
        $js = $this->js();

        self::assertStringContainsString("window.matchMedia('(max-width: 767.98px), (hover: none)').matches", $js);
        $toquePasta = strpos($js, "if (ehToque() && item.dataset.pexTipo === 'pasta') { entrar(Number(item.dataset.pexId)); return; }");
        $toqueNome  = strpos($js, "if (ehToque() && !e.shiftKey && item) { selecionar(chaveDoElemento(item)); abrirPreview(prev); return; }");
        $sel        = strpos($js, "else selecionar(chave);");
        self::assertNotFalse($toquePasta);
        self::assertNotFalse($toqueNome);
        self::assertNotFalse($sel);
        self::assertLessThan($sel, $toquePasta, 'o toque tem de ser decidido ANTES do selecionar');
        self::assertLessThan($toquePasta, $toqueNome, 'o nome do arquivo no toque é decidido antes da pasta (está dentro do ramo do link)');
        // Fora do toque, pasta NÃO entra no clique simples: entra no duplo clique / Enter.
        self::assertStringNotContainsString("if (item.dataset.pexTipo === 'pasta') entrar(Number(item.dataset.pexId));", $js);
        self::assertStringContainsString("el.lista.addEventListener('dblclick', function (e) {", $js);
    }

    #[TestDox('classificação salva por Categoria com a coluna desligada cai no padrão (Manual)')]
    public function testClassificarCategoriaSemColunaCaiNoPadrao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("if (chave === 'categoria' && !colunas.categoria) return { chave: 'manual', desc: false };", $js);
        // A leitura da classificação depende das colunas: `colunas` tem de ser lida ANTES.
        self::assertLessThan(
            strpos($js, 'let classificar = lerClassificar();'),
            strpos($js, 'let colunas     = lerColunas();')
        );
    }

    #[TestDox('painel de detalhes (dc L2271): 250px, sticky a 12px, #f7fafc, padding 18/16; ao lado da lista pela grade do corpo; abaixo dela no celular')]
    public function testPainelDeDetalhes(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\n\.pex-painel \{[^}]*--pex-ico: 64px;[^}]*position: sticky;[^}]*top: 12px;[^}]*width: 250px;[^}]*padding: 18px 16px;/', $css);
        self::assertMatchesRegularExpression('/--pex-painel-bg:\s+#f7fafc;/', $this->bloco('.pex'));
        self::assertMatchesRegularExpression('/\.pex--painel \.pex-corpo \{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0, 1fr\) 250px;[^}]*grid-template-rows: repeat\(7, auto\) 1fr;[^}]*column-gap: 14px;/', $css);
        self::assertStringContainsString('.pex--painel .pex-corpo > .pex-painel { grid-column: 2; grid-row: 1 / -1; }', $css);
        self::assertStringContainsString('.pex-painel[hidden] { display: none; }', $css, 'display:flex do painel venceria o atributo hidden');
        self::assertStringContainsString('.pex-cabecalho[hidden] { display: none; }', $css, 'o cabeçalho de colunas some fora do Detalhes');

        // Seleção (dc L4733).
        self::assertMatchesRegularExpression('/--pex-sel-bg:\s+#dcebf6;/', $this->bloco('.pex'));
        self::assertMatchesRegularExpression('/--pex-sel-anel:\s+#9fc3e3;/', $this->bloco('.pex'));
        self::assertStringContainsString('.pex-item.pex-item--sel { background: var(--pex-sel-bg); box-shadow: inset 0 0 0 1px var(--pex-sel-anel); }', $css);

        // Painel só com o que #pexDados tem; data rotulada pelo que ela é.
        $js = $this->js();
        self::assertStringContainsString("['Adicionado em', formatarDataHora(d.carregadoEm)],", $js);
        self::assertStringContainsString("['Conteúdo', partes.length ? partes.join(' · ') : 'Vazia'],", $js);
    }

    #[TestDox('375px: no celular a Lista vira uma coluna e o painel desce para baixo da lista — nada rola para o lado')]
    public function testCelularSemRolagemLateral(): void
    {
        $css   = $this->css();
        $ini   = strpos($css, '@media (max-width: 767.98px) {');
        self::assertNotFalse($ini);
        $media = substr($css, $ini, strpos($css, "\n}", $ini) - $ini);

        self::assertStringContainsString('.pex--m-lista .pex-lista { grid-auto-flow: row; grid-template-rows: none; grid-template-columns: minmax(0, 1fr); overflow-x: visible; }', $media);
        self::assertStringContainsString('.pex--painel .pex-corpo { display: block; }', $media);
        self::assertStringContainsString('.pex-painel { position: static; width: auto;', $media);
        self::assertStringContainsString('.pex--m-det .pex-item { grid-template-columns: minmax(0, 1fr); }', $media, 'Detalhes no celular: só o Nome');
        self::assertStringContainsString('.pex-alca { display: none; }', $media);
        // Celular COM toque: Nome + ⋮ (32px). Vem DEPOIS do bloco estreito para vencê-lo (mesma especificidade).
        $toque = strpos($css, '@media (hover: none) and (max-width: 767.98px) {');
        self::assertNotFalse($toque);
        self::assertGreaterThan($ini, $toque);
        self::assertStringContainsString('.pex--m-det .pex-item { grid-template-columns: minmax(0, 1fr) 32px; }', substr($css, $toque, strpos($css, "\n}", $toque) - $toque));
    }

    #[TestDox('tema escuro: toda variável --pex-* do claro tem o par em [data-bs-theme="dark"] (menos a fonte dos números)')]
    public function testTodoTokenTemParEscuro(): void
    {
        $claro  = $this->variaveis($this->bloco('.pex'));
        $escuro = $this->variaveis($this->bloco('[data-bs-theme="dark"] .pex-modal'));

        self::assertNotEmpty($claro);
        $semPar = array_values(array_diff($claro, $escuro, ['--pex-fonte-num']));
        self::assertSame([], $semPar, 'token sem par escuro: ' . implode(', ', $semPar));
        self::assertSame([], array_values(array_diff($escuro, $claro)), 'token escuro sem o claro');
    }

    #[TestDox('desempenho: lista e painel montados em DocumentFragment; a seleção troca a classe sem refazer a lista')]
    public function testRenderEmFragmento(): void
    {
        $js = $this->js();

        self::assertGreaterThanOrEqual(3, substr_count($js, 'document.createDocumentFragment()'), 'lista, ordem das colunas e painel');
        $ini = strpos($js, '    function selecionar(chave) {');
        self::assertNotFalse($ini);
        $corpo = substr($js, $ini, strpos($js, "\n    }", $ini) - $ini);
        self::assertStringContainsString('renderizarPainel();', $corpo);
        self::assertStringNotContainsString(' renderizar();', $corpo, 'selecionar não pode refazer a lista inteira');
    }
}
