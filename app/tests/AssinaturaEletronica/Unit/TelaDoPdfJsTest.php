<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * "Baixar PDF com selo" nas telas (etapa 3, A5 e tela verde), lido no texto dos scripts (o container não tem node).
 *
 * Arranjo: o teste monta o HTML com os textos literais do trecho do script (os que não são argumento de chamada, como
 * `T(...)` e `esc(...)`; os dois ramos de um `? :` entram, e o ramo vazio não pesa) e confere por filho direto onde o
 * desenho põe cada botão: na linha, depois de copiar, lembrete e vincular e antes de Abrir (linha 149); no detalhe,
 * entre "Continuar e enviar" e "Cancelar envio"; na tela verde, a caixa do código e o botão branco antes de Concluir.
 */
final class TelaDoPdfJsTest extends TestCase
{
    private const INTERNA = __DIR__ . '/../../../public/js/assinatura.js';
    private const PUBLICA = __DIR__ . '/../../../public/js/assinatura-publica.js';
    private const PDF = __DIR__ . '/../../../public/js/assinatura-pdf.js';
    private const CSS_PUBLICA = __DIR__ . '/../../../public/css/assinatura-publica.css';
    private const TEXTOS_TELA = __DIR__ . '/../../../translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml';

    #[TestDox('linha da lista: o botão de ícone do PDF é filho direto da célula, depois das ações do link e antes de Abrir')]
    public function testLinha(): void
    {
        $js = self::ler(self::INTERNA);
        $botao = self::html(self::trecho(self::corpo($js, 'botaoPdfDaLinha'), 'return '));
        $celula = "'<td class=\"bjaTdAc\">'";
        $linha = str_replace('botaoPdfDaLinha()', "'" . addslashes($botao) . "'", $celula . self::trecho($js, $celula));
        $html = new Crawler('<table><tr>' . self::html($linha) . '</table>');

        self::assertCount(1, $html->filter('td.bjaTdAc > button.bjaB.bjaI[data-bja-acao="lin-pdf"] + button.bjaB[data-bja-acao="abrir"] + button.bjaB.bjaI.bjaN[data-bja-acao="ctx"]'));
        self::assertCount(1, $html->filter('td.bjaTdAc > button[data-bja-acao="lin-pdf"] > i.bi.bi-file-earmark-pdf'));
        self::assertStringContainsString("(temLink(d) ? acoesDaLinha() : '') + botaoPdfDaLinha() + '<button", $js, 'depois de copiar link e lembrete');
        self::assertStringContainsString("aria-label=\"' + esc(T('pdf.baixar_rotulo')) + '\" data-tip=\"' + esc(T('pdf.baixar')) + '\"", $js);
    }

    #[TestDox('detalhe: o botão do PDF entre "Continuar e enviar" e "Cancelar envio"; "Baixar PDF" na folha branca')]
    public function testDetalhe(): void
    {
        $js = self::ler(self::INTERNA);
        $html = new Crawler('<div class="bjaCodBts">' . self::html(self::trecho($js, 'const bts = ')) . '</div>');

        self::assertCount(1, $html->filter('.bjaCodBts > button.bjaP[data-bja-acao="det-retomar"] + button.bjaP[data-bja-acao="det-pdf"] + button.bjaB.bjaR[data-bja-acao="det-cancelar"]'));
        self::assertCount(1, $html->filter('.bjaCodBts > button[data-bja-acao="det-pdf"] > i.bi.bi-file-earmark-pdf'));
        self::assertStringContainsString("esc(d.folhaBranca ? T('pdf.baixar_rotulo') : T('pdf.baixar'))", $js);
    }

    #[TestDox('menu de mais opções: "Baixar PDF com selo" depois de "Enviar lembrete" e antes de "Duplicar"')]
    public function testMenu(): void
    {
        $js = self::ler(self::INTERNA);
        $posicoes = array_map(static fn (string $acao): int|false => strpos($js, "'" . $acao . "']); }"), ['ctx-lembrete', 'ctx-pdf', 'ctx-duplicar']);

        self::assertNotContains(false, $posicoes);
        self::assertTrue($posicoes[0] < $posicoes[1] && $posicoes[1] < $posicoes[2]);
        self::assertStringContainsString("L.push(['bi-file-earmark-pdf', T('pdf.baixar'), 'ctx-pdf']);", $js);
        self::assertStringContainsString("case 'ctx-pdf': { const id = s.ctx.id; fecharCtx(); baixarPdf(id); break; }", $js);
    }

    #[TestDox('tela verde: a caixa do código e "Baixar PDF com selo" antes de Concluir, filhos diretos como no desenho')]
    public function testTelaVerde(): void
    {
        $js = self::ler(self::PUBLICA);
        $html = new Crawler('<div id="raiz">' . self::html(self::trecho(self::corpo($js, 'mostrarOk'), "camada('ok').innerHTML = ")) . '</div>');

        self::assertCount(1, $html->filter('.bjaOkCorpo > .bjaOkT + .bjaOkD + div.bjaOkCod + div.bjaOkBts'));
        self::assertCount(1, $html->filter('.bjaOkBts > button.bjaOkPdf[data-bja-acao="baixar-pdf"] + button.bjaOkFim[data-bja-acao="concluir"]'));
        self::assertCount(1, $html->filter('.bjaOkBts > button.bjaOkPdf > i.bi.bi-file-earmark-pdf'));
        self::assertStringContainsString("esc(TP('baixar'))", $js);
        self::assertStringContainsString("case 'baixar-pdf': baixarPdf(); break;", $js);
        self::assertStringContainsString('window.BJAssinaPdf.baixar(url.pdf, {', self::corpo($js, 'baixarPdf'));
    }

    #[TestDox('"Gerando o PDF..." fica até o fim; a falha mostra o texto do servidor (429) ou "Não foi possível gerar o PDF."')]
    public function testAvisosDaGeracao(): void
    {
        foreach ([self::INTERNA => 'T(\'pdf.', self::PUBLICA => 'TP(\''] as $arquivo => $t) {
            $js = self::ler($arquivo);
            $baixar = self::corpo($js, 'baixarPdf');
            self::assertStringContainsString('aoIniciar: () => avisar(' . $t . "gerando'), '', true),", $baixar, $arquivo);
            self::assertStringContainsString('aoConcluir: () => avisar(' . $t . "gerado')),", $baixar, $arquivo);
            self::assertStringContainsString('aoFalhar: e => avisar((e && e.texto) || ' . $t . "falhou'), 'erro'),", $baixar, $arquivo);
            self::assertStringContainsString('if (!fixo) { tToast = setTimeout(', self::corpo($js, 'avisar'), $arquivo);
        }
        $pdf = Yaml::parseFile(self::TEXTOS_TELA)['assinatura_eletronica_tela']['js']['pdf'] ?? [];
        self::assertSame('Gerando o PDF...', $pdf['gerando'] ?? null);
        self::assertSame('Muitos PDFs sendo gerados agora. Tente de novo em instantes.', $pdf['muitos'] ?? null);
        self::assertSame('Muitos PDFs gerados nos últimos minutos. Tente de novo em alguns minutos.', $pdf['limite'] ?? null);
        self::assertSame('O PDF deste documento está sendo gerado. Tente de novo em alguns minutos.', $pdf['documento_gerando'] ?? null);
        self::assertSame('Não foi possível gerar o PDF. Tente de novo.', $pdf['falhou'] ?? null);
    }

    #[TestDox('download: o aviso de início vem depois da guarda do segundo clique; PDF sem %%EOF no fim não é salvo')]
    public function testPdfCortadoE429(): void
    {
        $js = self::ler(self::PDF);
        $baixar = self::corpo($js, 'baixar');

        self::assertLessThan(strpos($baixar, 'if (o.aoIniciar) { o.aoIniciar(); }'), strpos($baixar, 'if (!url || emCurso[url]) { return false; }'));
        self::assertStringContainsString('if (!r.ok) { throw await erroDaResposta(r); }', $baixar);
        self::assertStringContainsString("if (corpo && typeof corpo.erro === 'string') { e.texto = corpo.erro; }", self::corpo($js, 'erroDaResposta'));
        self::assertStringContainsString("return fim.indexOf('%%EOF') >= 0;", self::corpo($js, 'inteiro'));
        $confere = strpos($baixar, "if (!(await inteiro(blob))) { throw new Error(");
        self::assertIsInt($confere);
        self::assertLessThan(strpos($baixar, 'entregar(blob, '), $confere, 'confere o fim antes de salvar');
    }

    #[TestDox('estilos da tela verde tirados do desenho: código em monospace 15 com borda, botão branco de 44 px')]
    public function testEstilos(): void
    {
        $css = self::ler(self::CSS_PUBLICA);

        self::assertStringContainsString('.bjaPub .bjaOkCod { font: bold 15px monospace; letter-spacing: 1px; padding: 7px 12px; border: 1px solid rgba(255, 255, 255, .55); margin-top: 6px; }', $css);
        self::assertStringContainsString('.bjaPub .bjaOkPdf { height: 44px; padding: 0 18px; border: none; border-radius: 3px; background: #fff; color: #145a32; font: inherit; font-size: 15px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; margin: 0; }', $css);
    }

    #[TestDox('textos do desenho no catálogo e nenhum travessão nos textos novos')]
    public function testTextos(): void
    {
        $pdf = Yaml::parseFile(self::TEXTOS_TELA)['assinatura_eletronica_tela']['js']['pdf'] ?? [];

        self::assertSame('Baixar PDF com selo', $pdf['baixar'] ?? null);
        self::assertSame('Baixar PDF', $pdf['baixar_rotulo'] ?? null);
        self::assertSame('PDF com selo gerado.', $pdf['gerado'] ?? null);
        self::assertSame('assinado_{nome}.pdf', $pdf['nome_arquivo'] ?? null);
        foreach ([self::PDF, self::PUBLICA, self::INTERNA] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/\x{2014}|\x{2013}/u', self::ler($arquivo), $arquivo);
        }
    }

    #[TestDox('download: pede o PDF com a sessão, usa o nome do Content-Disposition (UTF-8 primeiro) e um clique por vez')]
    public function testDownload(): void
    {
        $js = self::ler(self::PDF);

        self::assertStringContainsString("fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })", $js);
        self::assertStringContainsString("/filename\\*=UTF-8''([^;]+)/i", $js);
        self::assertStringContainsString('if (!url || emCurso[url]) { return false; }', $js);
        self::assertStringContainsString('window.BJAssinaPdf = { baixar: baixar };', $js);
    }

    private static function ler(string $arquivo): string
    {
        $texto = file_get_contents($arquivo);
        self::assertIsString($texto, $arquivo);

        return $texto;
    }

    /** O corpo da `function <nome>(...) { ... }`, sem as chaves de fora (os textos entre aspas não contam chave). */
    private static function corpo(string $js, string $nome): string
    {
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertIsInt($inicio, 'faltou a function ' . $nome);
        $abre = strpos($js, '{', $inicio);
        self::assertIsInt($abre);
        $profundidade = 0;
        for ($i = $abre, $n = \strlen($js); $i < $n; ++$i) {
            $c = $js[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::fimDoTexto($js, $i);
                continue;
            }
            if ($c === '{') {
                ++$profundidade;
            } elseif ($c === '}' && --$profundidade === 0) {
                return substr($js, $abre + 1, $i - $abre - 1);
            }
        }
        self::fail('a function ' . $nome . ' não fecha');
    }

    /** Do marcador até o `;` que fecha a instrução (fora de textos e parênteses), sem o marcador. */
    private static function trecho(string $js, string $marcador): string
    {
        $inicio = strpos($js, $marcador);
        self::assertIsInt($inicio, 'faltou ' . $marcador);
        $profundidade = 0;
        for ($i = $inicio + \strlen($marcador), $n = \strlen($js); $i < $n; ++$i) {
            $c = $js[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::fimDoTexto($js, $i);
                continue;
            }
            $profundidade += match ($c) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
            if ($profundidade === 0 && $c === ';') {
                return substr($js, $inicio + \strlen($marcador), $i - $inicio - \strlen($marcador));
            }
        }
        self::fail('instrução sem fim: ' . $marcador);
    }

    /** Os textos entre aspas simples do trecho, em ordem, menos os que são argumento de uma chamada (`T(`, `esc(`...). */
    private static function html(string $trecho): string
    {
        $html = '';
        $pilha = [];
        for ($i = 0, $n = \strlen($trecho); $i < $n; ++$i) {
            $c = $trecho[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $fim = self::fimDoTexto($trecho, $i);
                if ($c === "'" && !\in_array(true, $pilha, true)) {
                    $html .= stripslashes(substr($trecho, $i + 1, $fim - $i - 1));
                }
                $i = $fim;
                continue;
            }
            if ($c === '(') {
                $pilha[] = $i > 0 && preg_match('/[\w.]/', rtrim(substr($trecho, 0, $i))[-1] ?? ' ') === 1;
            } elseif ($c === ')') {
                array_pop($pilha);
            }
        }

        return $html;
    }

    private static function fimDoTexto(string $js, int $abre): int
    {
        $aspa = $js[$abre];
        for ($i = $abre + 1, $n = \strlen($js); $i < $n; ++$i) {
            if ($js[$i] === '\\') {
                ++$i;
            } elseif ($js[$i] === $aspa) {
                return $i;
            }
        }
        self::fail('texto sem fim');
    }
}
