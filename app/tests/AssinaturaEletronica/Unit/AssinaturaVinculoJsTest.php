<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\Tests\AssinaturaEletronica\Support\LeituraDoScript;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * "Vincular à pasta de Expediente" na tela, lido no texto dos scripts (spec assinatura-documentos-etapa-3, E3-10; desenho
 * 17, linhas 149, 222 e 277): a janela e os botões por filho direto, a costura no assinatura.js na ordem do desenho, os
 * estilos com os valores do desenho e os textos literais.
 */
#[CoversNothing]
final class AssinaturaVinculoJsTest extends TestCase
{
    use LeituraDoScript;

    private const VINCULO = __DIR__ . '/../../../public/js/assinatura-vinculo.js';
    private const TELA = __DIR__ . '/../../../public/js/assinatura.js';
    private const CSS = __DIR__ . '/../../../public/css/assinatura.css';
    private const TEXTOS = __DIR__ . '/../../../translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml';

    #[TestDox('janela (linha 277): fundo, caixa, cabeçalho, info, busca, lista e rodapé com Fechar, por filho direto')]
    public function testArranjoDaJanela(): void
    {
        $js = self::ler(self::VINCULO);
        $html = new Crawler('<div id="camada">' . self::esqueleto($js, 'janelaHtml', ['listaHtml' => self::esqueleto($js, 'itemHtml')]) . '</div>');

        self::assertCount(1, $html->filter('#camada > .bjaVincFundo > .bjaCard.bjaVincCx[role="dialog"][aria-modal="true"]'));
        self::assertCount(1, $html->filter('.bjaVincCx > .bjaHd:first-child + .bjaVincCorpo + .bjaModalPe:last-child'));
        self::assertCount(1, $html->filter('.bjaVincCx > .bjaHd > i.bi.bi-folder-symlink'));
        self::assertCount(1, $html->filter('.bjaVincCorpo > .bjaVincInfo:first-child + input.bjaF[data-bja-vinc-q] + .bjaVincLista:last-child'));
        self::assertCount(1, $html->filter('.bjaVincLista > button.bjaVincIt[type="button"] > b:first-child + span.bjaVincCli + span.bjaVincAcao:last-child'));
        self::assertCount(1, $html->filter('.bjaModalPe > button'), 'só o Fechar: sem botão de NUP digitado (E3-10)');
        self::assertCount(1, $html->filter('.bjaModalPe > button.bjaB.bjaN[data-bja-vinc-fechar]'));

        $janela = self::corpo($js, 'janelaHtml');
        self::assertStringContainsString("aria-label=\"' + esc(T('vincular')) + '\"", $janela);
        self::assertStringContainsString("esc(T('vincular_expediente'))", $janela);
        self::assertStringContainsString("aria-label=\"' + esc(T('buscar')) + '\" placeholder=\"' + esc(T('buscar_placeholder'))", $janela);
        self::assertStringContainsString("T(v.st === 'concluido' ? 'info_concluido' : 'info_aguardando', { nome: v.doc.nome })", $janela);
        self::assertStringContainsString("'<div class=\"bjaVincVazio\">' + esc(T('vazio')) + '</div>'", self::corpo($js, 'listaHtml'));
    }

    #[TestDox('botões e link (linhas 149 e 222): bjaB bjaI bi-folder-symlink na linha, bjaB no detalhe, bi-folder-check no texto')]
    public function testBotoesELink(): void
    {
        $js = self::ler(self::VINCULO);

        $linha = new Crawler('<div id="r">' . self::esqueleto($js, 'botaoDaLinha') . '</div>');
        self::assertCount(1, $linha->filter('#r > button.bjaB.bjaI[type="button"][data-bja-acao="lin-vincular"] > i.bi.bi-folder-symlink'));
        self::assertStringContainsString("const r = T('vincular_expediente');", self::corpo($js, 'botaoDaLinha'), 'aria-label e dica "Vincular à pasta de Expediente"');

        $detalhe = new Crawler('<div id="r">' . self::esqueleto($js, 'botaoDoDetalhe') . '</div>');
        self::assertCount(1, $detalhe->filter('#r > button.bjaB[type="button"][data-bja-acao="det-vincular"] > i.bi.bi-folder-symlink'));
        self::assertCount(0, $detalhe->filter('.bjaI'));

        self::assertStringContainsString("['bi-folder-symlink', T('vincular'), 'ctx-vincular']", self::corpo($js, 'itemDoMenu'));
        self::assertStringContainsString("'<a class=\"' + classe + '\" href=\"' + esc(d.vinculo.url) + '\"><i class=\"bi bi-folder-check\"></i>' + esc(texto(d.vinculo)) + '</a>'", $js);
        self::assertStringContainsString("T((x.feito ? 'na_pasta' : 'ira_para') + (x.cliente ? '_cliente' : ''), { nup: x.nup, cliente: x.cliente })", $js);
    }

    #[TestDox('regra do menu (E3-10): só aguardando ou concluído, sem pasta e fora da Lixeira; só com as duas URLs')]
    public function testRegraDoMenu(): void
    {
        $js = self::ler(self::VINCULO);

        self::assertStringContainsString("const pode = (d, st) => ligado() && !!d && !d.vinculado && !d.naLixeira && (st === 'concluido' || st === 'aguardando');", $js);
        self::assertStringContainsString('const ligado = () => !!(url.vincular && url.pastas && camada);', $js);
        foreach (['botaoDaLinha', 'botaoDoDetalhe', 'itemDoMenu', 'abrir'] as $funcao) {
            self::assertStringContainsString('pode(d, st)', self::corpo($js, $funcao), $funcao . ' segue a regra');
        }
    }

    #[TestDox('vincExec: a pasta pelo id, avisos do desenho (salvo, será salvo e o erro em vermelho) e o concluído que espera o PDF com selo')]
    public function testVincular(): void
    {
        $corpo = self::corpo(self::ler(self::VINCULO), 'vincular');

        self::assertStringContainsString('ctx.postJson(ctx.comId(url.vincular, d.id), { pasta: p.id })', $corpo);
        self::assertStringContainsString("ctx.avisar(T('sera_salvo', { nup: p.nup }))", $corpo);
        self::assertStringContainsString("ctx.avisar(T('salvo', { nup: p.nup }))", $corpo);
        self::assertStringContainsString("ctx.avisar(T('erro'), 'erro')", $corpo);
        self::assertStringContainsString("ctx.avisar((e && e.texto) || T('erro'), 'erro')", $corpo);
        self::assertStringContainsString(
            "} else if (r.aplicado) { ctx.avisar(T('salvo', { nup: p.nup })); } else if (r.pendente) { ctx.avisar(T('sera_salvo_pdf', { nup: p.nup })); } else { ctx.avisar(T('erro'), 'erro'); }",
            $corpo,
            'concluído e não aplicado: pendente avisa normal (o PDF com selo ainda está na fila), o resto é o erro em vermelho',
        );
    }

    #[TestDox('costura no assinatura.js na ordem do desenho: texto depois do sub, botão antes de Abrir, menu antes de Duplicar')]
    public function testCostura(): void
    {
        $tela = self::ler(self::TELA);

        self::assertStringContainsString("'</div>' + (V ? V.textoDaLinha(d) : '') + '</td>'", self::corpo($tela, 'linhaHtml'));
        self::assertStringContainsString("(temLink(d) ? acoesDaLinha() : '') + (V ? V.botaoDaLinha(d, d.stA) : '') + botaoPdfDaLinha() + '<button type=\"button\" class=\"bjaB\" data-bja-acao=\"abrir\">'", self::corpo($tela, 'linhaHtml'));

        $ctx = self::corpo($tela, 'ctxHtml');
        self::assertLessThan(strpos($ctx, "'ctx-duplicar'"), strpos($ctx, 'V.itemDoMenu(d0, d0.stA)'));
        self::assertGreaterThan(strpos($ctx, "'ctx-lembrete'"), strpos($ctx, 'V.itemDoMenu(d0, d0.stA)'));

        $det = self::corpo($tela, 'desenharDetalhe');
        $botao = strpos($det, 'V.botaoDoDetalhe(d, st)');
        self::assertIsInt($botao);
        self::assertGreaterThan(strpos($det, 'det-retomar'), $botao);
        self::assertLessThan(strpos($det, 'det-cancelar'), $botao);
        self::assertStringContainsString("'<div class=\"bjaCodBts\">' + bts + '</div>' : '') + (V ? V.linkDoDetalhe(d) : '') + '</section>'", $det);

        $acoes = self::corpo($tela, 'acao');
        self::assertStringContainsString("case 'lin-vincular':", $acoes);
        self::assertStringContainsString("case 'ctx-vincular':", $acoes);
        self::assertStringContainsString("case 'det-vincular': if (s.det && V) { V.abrir(s.det, stAtual(s.det)); } break;", $acoes);
        self::assertStringContainsString("camada: $('bjaCamVinc')", $tela);
    }

    #[TestDox('estilos com os valores do desenho (linhas 149, 222 e 277)')]
    public function testEstilos(): void
    {
        $css = self::ler(self::CSS);

        foreach ([
            '.bja .bjaVincLin { display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; font-size: 12px; font-weight: bold; color: var(--bja-verde-tx); text-decoration: none; }',
            '.bja .bjaVincDet { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: bold; color: var(--bja-verde-tx); text-decoration: none; }',
            '.bja .bjaVincFundo { position: fixed; inset: 0; z-index: 2147483100; background: rgba(11, 53, 80, .55); display: flex; align-items: center; justify-content: center; padding: 14px; }',
            '.bja .bjaVincCx { width: 100%; max-width: 560px; overflow: hidden; box-sizing: border-box; }',
            '.bja .bjaVincCorpo { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }',
            '.bja .bjaVincInfo { font-size: 13px; color: var(--bja-t2); }',
            '.bja .bjaVincLista { max-height: 300px; overflow-y: auto; border: 1px solid var(--bja-div); }',
            '.bja .bjaVincIt:hover { background: #3b8fc2; color: #fff; }',
            '.bja .bjaVincIt > b { min-width: 64px; }',
            '.bja .bjaVincIt > .bjaVincCli { flex: 1; min-width: 0; }',
            '.bja .bjaVincIt > .bjaVincAcao { font-size: 12px; }',
            '.bja .bjaVincVazio { padding: 14px; font-size: 13px; color: var(--bja-mudo); }',
        ] as $regra) {
            self::assertStringContainsString($regra, $css);
        }
        self::assertStringContainsString('display: flex; gap: 10px; width: 100%; padding: 8px 10px; border: none; border-bottom: 1px solid var(--bja-div);', $css);
        self::assertStringContainsString('font: inherit; font-size: 13.5px; color: var(--bja-texto); text-align: left; cursor: pointer;', $css);
    }

    #[TestDox('o verde do vínculo é variável: o do desenho no tema claro e na linha pintada, um verde claro só no tema escuro')]
    public function testVerdeDoVinculoPorTema(): void
    {
        $css = self::ler(self::CSS);

        self::assertSame(['#145a32', '#7fd1a1', '#145a32'], self::valoresDaVariavel($css, '--bja-verde-tx'), 'claro, escuro e linha pintada, nessa ordem');
        self::assertSame(1, substr_count($css, '#7fd1a1'), 'o verde claro só na variável do tema escuro');
        self::assertDoesNotMatchRegularExpression('/bjaVinc(Lin|Det)[^{]*\{[^}]*#[0-9a-f]{6}/i', $css, 'as regras do vínculo usam a variável, não a cor escrita');
        foreach (["\n.bja {", "\n[data-bs-theme=\"dark\"] .bja {", "\n[data-bs-theme=\"dark\"] .bja .bjaLin.tem-cor {"] as $bloco) {
            $inicio = strpos($css, $bloco);
            self::assertIsInt($inicio, $bloco);
            self::assertStringContainsString('--bja-verde-tx:', substr($css, $inicio, (int) strpos($css, '}', $inicio) - $inicio), $bloco);
        }
    }

    /** @return list<string> os valores dados à variável, na ordem do arquivo */
    private static function valoresDaVariavel(string $css, string $variavel): array
    {
        preg_match_all('/' . preg_quote($variavel, '/') . ':\s*([^;]+);/', $css, $achados);

        return $achados[1];
    }

    #[TestDox('textos do desenho no catálogo js.vinculo, sem travessão, sem o aviso da aba Expediente nem o botão de NUP digitado')]
    public function testTextos(): void
    {
        $textos = Yaml::parseFile(self::TEXTOS)['assinatura_eletronica_tela']['js']['vinculo'];

        self::assertSame('Vincular à pasta', $textos['vincular']);
        self::assertSame('Vincular à pasta de Expediente', $textos['vincular_expediente']);
        self::assertSame('Na pasta {nup} · {cliente}', $textos['na_pasta_cliente']);
        self::assertSame('Irá para a pasta {nup} · {cliente}', $textos['ira_para_cliente']);
        self::assertSame('{nome}: o PDF com selo será salvo na pasta escolhida.', $textos['info_concluido']);
        self::assertSame('{nome}: será salvo na pasta quando todos assinarem.', $textos['info_aguardando']);
        self::assertSame('Buscar pasta', $textos['buscar']);
        self::assertSame('Buscar por NUP, cliente ou ação', $textos['buscar_placeholder']);
        self::assertSame('Nenhuma pasta encontrada.', $textos['vazio']);
        self::assertSame('Fechar', $textos['fechar']);
        self::assertSame('Documento salvo na pasta {nup}.', $textos['salvo']);
        self::assertSame('Será salvo na pasta {nup} quando concluído.', $textos['sera_salvo']);
        self::assertSame('Será salvo na pasta {nup} quando o PDF com selo ficar pronto.', $textos['sera_salvo_pdf']);
        self::assertSame('Não foi possível vincular o arquivo.', $textos['erro']);

        $js = self::ler(self::VINCULO);
        self::assertStringNotContainsString('aba Expediente', $js . implode(' ', $textos));
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', implode(' ', $textos) . $js);
    }
}
