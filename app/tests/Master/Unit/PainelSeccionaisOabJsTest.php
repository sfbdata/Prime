<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Bloco "SECCIONAIS VISÍVEIS" do mapa (desenho 05, Perfis 1.2, VS 21.10.26, L116-127; spec master-seccionais-oab),
 * lido como FOLHA: não há navegador no PHPUnit, então o que se prova é o contrato do painel.js e do mapa-brasil.js
 * com o desenho, com os templates e entre si (modo OAB, visibilidade no servidor, aviso de recarga, CSRF).
 */
final class PainelSeccionaisOabJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';
    private const NOTA = 'Clique numa seccional para ver a sede. Botão direito na lista mostra ou oculta no mapa; botão direito no mapa registra a divulgação (material enviado, reunião, parceria). O ponto colorido indica seccional já trabalhada. Fonte: Conselho Federal da OAB.';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
    }

    /** Texto em português (a fonte) de uma chave do catálogo: o literal do desenho mora lá desde a P2a do idioma. */
    private static function texto(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }
        self::assertIsString($no, $chave);

        return $no;
    }

    /** O trecho do painel.js entre o comentário do bloco e o renderIdades. */
    private static function bloco(): string
    {
        $js = self::arquivo('public/js/master/painel.js');
        $inicio = strpos($js, '/* ---------- Seccionais da OAB');
        $fim = strpos($js, 'function renderIdades()');
        self::assertNotFalse($inicio, 'o bloco das seccionais existe');
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    /** Corpo de uma função do bloco, do `function nome(` até a próxima função ou constante de topo. */
    private static function funcao(string $nome): string
    {
        $bloco = self::bloco();
        $inicio = strpos($bloco, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'a função ' . $nome . ' existe');
        preg_match('/\n  (?:function |const |let |\/\/|elSecao|document|window)/', $bloco, $m, PREG_OFFSET_CAPTURE, $inicio + 10);

        return substr($bloco, $inicio, isset($m[0]) ? $m[0][1] - $inicio : null);
    }

    #[TestDox('modo OAB (L875 e L898): "quem" com Pessoas, Escritórios e OAB, só no Brasil; o padrão é Escritórios; o bloco só no modo OAB')]
    public function testModoOab(): void
    {
        $js = self::arquivo('public/js/master/painel.js');

        self::assertStringContainsString("seg([['pes', T('master.js.mapa.pessoas'), 'bi-people'], ['esc', T('master.js.mapa.escritorios'), 'bi-buildings'], ['oab', T('master.js.mapa.oab'), 'bi-bank'], ['ind', T('master.js.mapa.indicacoes'), 'bi-share']], S.mapaQuem, 'data-mapa-quem', true)", $js);
        self::assertStringContainsString("mapaModo: 'br', mapaQuem: 'esc',", $js, 'Escritórios por padrão');
        self::assertStringContainsString("const ehModoOab = () => S.mapaModo !== 'mundo' && S.mapaQuem === 'oab';", $js);
        self::assertStringContainsString("if (ehModoOab()) return { A: secInscricoes(), C: (M.pessoas.geral || {}).C || {}", $js, 'o número da UF são as inscrições');
        self::assertMatchesRegularExpression("/\\+ \\(br \\? linhasDoBrasil\\(G\\) : linhasDoMundo\\(totBr, paisesExt\\)\\)\\s*\\n\\s*\\+ \\(ehOab \\? blocoSeccionais\\(\\) : ''\\)/", $js);
        self::assertStringContainsString("ehModoOab() ? T('master.js.mapa.por_seccional')", $js);
        self::assertStringContainsString("const usuTit = ehModoInd() ? T('master.js.mapa.col_ind_' + S.mapaInd) : ehOab ? T('master.js.mapa.inscricoes')", $js);
        self::assertSame(['OAB', 'inscrições por seccional da OAB', 'Inscrições'], array_map(self::texto(...), ['master.js.mapa.oab', 'master.js.mapa.por_seccional', 'master.js.mapa.inscricoes']));
        self::assertStringContainsString("const quem = ehOab ? 'oab' : ehModoInd() ? 'ind' : S.mapaQuem === 'esc' ? 'esc' : 'pes';", $js, 'o iframe recebe quem=oab ou quem=ind');
    }

    #[TestDox('linha da UF no modo OAB (L898): "OAB/<UF> · <Estado>", com mão, e o clique abre o cartão da sede')]
    public function testLinhaDaUf(): void
    {
        $js = self::arquivo('public/js/master/painel.js');

        self::assertStringContainsString("const acao = ehOab ? ' is-oab\" role=\"button\" tabindex=\"0\" data-sec-linha=\"' + esc(uf) + '\"' : '\"';", $js);
        self::assertStringContainsString("T('master.js.seccionais.linha', { uf, estado: NM[uf] || uf })", $js);
        self::assertSame('OAB/{uf} · {estado}', self::texto('master.js.seccionais.linha'));
        self::assertStringContainsString("if (alvo.hasAttribute('data-sec-linha')) { SEC.aberta = alvo.dataset.secLinha; return renderMapa(); }", $js);
        self::assertStringContainsString('.mp-uf-lin.is-oab { cursor: pointer; }', self::arquivo('public/css/master-seccionais.css'));
    }

    #[TestDox('literais do desenho: cabeçalho, "Mostrar todas"/"Todas visíveis", nota e as inscrições no plural')]
    public function testLiterais(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("<b>' + T('master.js.seccionais.titulo') + '</b>", $bloco);
        self::assertSame('SECCIONAIS VISÍVEIS', self::texto('master.js.seccionais.titulo'));
        self::assertStringContainsString("(algumaOculta ? T('master.js.seccionais.mostrar_todas') : T('master.js.seccionais.todas_visiveis'))", $bloco);
        self::assertSame(['Mostrar todas', 'Todas visíveis'], [self::texto('master.js.seccionais.mostrar_todas'), self::texto('master.js.seccionais.todas_visiveis')]);
        self::assertStringContainsString("esc(T('master.js.seccionais.nota'))", $bloco);
        self::assertSame(self::NOTA, self::texto('master.js.seccionais.nota'));
        self::assertStringContainsString("(n === 1 ? T('master.js.seccionais.inscricao_um') : T('master.js.seccionais.inscricoes', { n: num(n) }))", $bloco);
        self::assertSame(['1 inscrição de usuários do BlueJus', '{n} inscrições de usuários do BlueJus'], [self::texto('master.js.seccionais.inscricao_um'), self::texto('master.js.seccionais.inscricoes')]);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $bloco, 'sem travessão em texto novo');
    }

    #[TestDox('cartão da sede (L903) na ordem do desenho: título "OAB/<UF> · Seccional <Estado>", inscrições, endereço, telefone, site, CFOAB e Editar sede')]
    public function testCartao(): void
    {
        $cartao = self::funcao('secCartao');

        $marcas = ['bi-bank', "T('master.js.seccionais.cartao_titulo', { uf: s.uf, estado: secEstado(s) })", 'bi-x-lg', 'bi-people', 'bi-geo-alt', 'bi-telephone', "bi-globe2\"></i>' + T('master.js.seccionais.site')", "bi-box-arrow-up-right\"></i>' + T('master.js.seccionais.cfoab')", "bi-pencil\"></i> ' + T('master.js.seccionais.editar_sede')"];
        $posicoes = array_map(static fn (string $m): int|false => strpos($cartao, $m), $marcas);
        self::assertNotContains(false, $posicoes, 'todos os itens do desenho');
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes);
        self::assertSame('OAB/{uf} · Seccional {estado}', self::texto('master.js.seccionais.cartao_titulo'));
        self::assertStringContainsString("esc(s.endereco || T('master.js.seccionais.sem_endereco'))", $cartao);
        self::assertSame('Endereço da sede: consulte a página oficial', self::texto('master.js.seccionais.sem_endereco'));
        self::assertStringContainsString("const SEC_CFOAB = 'https://www.oab.org.br/institucionalconselhofederal/asiseccional';", self::bloco());
        self::assertStringContainsString('target="_blank" rel="noopener"', $cartao);
        self::assertStringContainsString("const secSite = s => (/^https?:\\/\\/\\S+$/i.test(s || '') ? s : null);", self::bloco(), 'o link do site sai com http:// ou https://');
        self::assertStringNotContainsString('ultimas', self::bloco(), 'sem a nota e as "5 últimas" no cartão');
    }

    #[TestDox('nenhum dado do servidor entra no HTML sem esc(): nome, endereço, telefone, site, UF, rótulo da marca')]
    public function testEscape(): void
    {
        $bloco = self::bloco();

        foreach (['esc(s.telefone)', 'esc(site)', 'esc(s.uf)', 'esc(tit)'] as $trecho) {
            self::assertStringContainsString($trecho, $bloco);
        }
        self::assertDoesNotMatchRegularExpression('/\'\s*\+\s*(s|m)\.(nome|endereco|telefone|site|rotulo)\b/', $bloco, 'campo concatenado cru');
        self::assertStringContainsString("'<span class=\"mp-sec-ponto\" style=\"--cor:' + m.cor + '\"></span>'", self::funcao('secChip'));
        self::assertStringContainsString('const m = s.marca && secCor(s.marca.cor) ? s.marca : null', self::funcao('secChip'), 'a cor só entra validada (#rrggbb)');
    }

    #[TestDox('chips (L902): clique abre o cartão, botão direito grava a visibilidade no servidor, ponto só com status e cor, tooltip do desenho; sem localStorage')]
    public function testChips(): void
    {
        $bloco = self::bloco();
        $chip = self::funcao('secChip');

        self::assertStringContainsString("elSecao.addEventListener('contextmenu', e => {\n    const chip = e.target.closest('[data-sec-oab] [data-sec-uf]');", $bloco);
        self::assertStringContainsString("secPostar(SEC.dados.urlVisibilidade.replace('/XX/', '/' + uf + '/'), { visivel: s.visivel }, secAvisarMapa,", self::funcao('secAlternar'), 'manda o estado desejado, não um alternar');
        self::assertStringContainsString("SEC.dados.seccionais.forEach(s => { s.visivel = true; }); renderMapa();\n    secPostar(SEC.dados.urlMostrarTodas, {}, secAvisarMapa,", self::funcao('secTodas'));
        self::assertStringContainsString("if (e.target.closest('[data-sec-todas]')) return secTodas();", $bloco);
        self::assertStringNotContainsString('localStorage', $bloco);
        self::assertStringContainsString("(s.visivel ? '' : ' is-oculta')", $chip);
        self::assertStringContainsString("(m ? '<span class=\"mp-sec-ponto\"", $chip);
        self::assertSame('OAB/{uf} · {estado} · clique para ver a sede, botão direito para mostrar ou ocultar no mapa', self::texto('master.js.seccionais.chip_titulo'));
        self::assertSame('OAB/{uf} · {estado} · {status} em {data} · clique para ver a sede, botão direito para mostrar ou ocultar no mapa', self::texto('master.js.seccionais.chip_titulo_marca'));
    }

    #[TestDox('Editar sede: rótulos do cartão (L904) ou da janela (L890), site http ou https, POST JSON com o token no header X-CSRF-Token')]
    public function testEditarSede(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("cartao: ['master.js.seccionais.campo_endereco', 'master.js.seccionais.campo_telefone', 'master.js.seccionais.campo_site'],", $bloco);
        self::assertStringContainsString("janela: ['master.js.janela_oab.campo_endereco_sede', 'master.js.janela_oab.campo_telefone', 'master.js.janela_oab.campo_site']", $bloco);
        self::assertSame(['Endereço da sede da OAB/{uf} (copie da página oficial do CFOAB)', 'Telefone da seccional (opcional)', 'Site oficial da seccional (https://...), opcional'], array_map(self::texto(...), ['master.js.seccionais.campo_endereco', 'master.js.seccionais.campo_telefone', 'master.js.seccionais.campo_site']));
        self::assertSame(['Endereço da sede da OAB/{uf}', 'Telefone (opcional)', 'Site oficial (https://...), opcional'], array_map(self::texto(...), ['master.js.janela_oab.campo_endereco_sede', 'master.js.janela_oab.campo_telefone', 'master.js.janela_oab.campo_site']));
        self::assertStringContainsString("caixa('bi-bank', T('master.js.seccionais.editar_sede'),", $bloco);
        self::assertStringContainsString("ok = site === '' || /^https?:\\/\\/\\S+$/i.test(site)", self::funcao('secAtualizarEdicao'));
        self::assertStringContainsString("'X-CSRF-Token': SEC.dados.token", $bloco);
        self::assertStringContainsString("SEC.dados.urlSede.replace('/XX/', '/' + uf + '/')", $bloco);
    }

    #[TestDox('iframe no modo OAB: inscrições das seccionais validadas, marcador no centroide só das visíveis, menu com os 6 status, as cores e a data')]
    public function testIframe(): void
    {
        $mapa = self::arquivo('public/js/master/mapa-brasil.js');
        $tpl = self::arquivo('templates/master/mapa_brasil.html.twig');

        self::assertStringContainsString("const MODO_OAB=new URLSearchParams(location.search).get('quem')==='oab';", $mapa);
        self::assertStringContainsString('return window.BJAgregado.validar({A}).A', $mapa, 'as inscrições passam pela mesma validação do agregado');
        $inicio = strpos($mapa, '/* Seccionais da OAB');
        self::assertNotFalse($inicio);
        $oab = substr($mapa, $inicio);
        self::assertStringStartsWith('(()=>{if(!MODO_OAB)return;', substr($oab, (int) strpos($oab, '(()=>{')), 'fora do modo OAB, nada de marcador nem de divulgação');
        self::assertStringContainsString("const STATUS=['env','vis','neg','cli','ret','sem']", $oab);
        self::assertStringContainsString('features.filter(f=>SEC[uf(f)]&&SEC[uf(f)].visivel)', $oab);
        self::assertStringContainsString('proj(d3.geoCentroid(f))', $oab);
        self::assertStringContainsString("escH(T('master.seccionais.status.'+k))", $oab);
        self::assertStringContainsString('CORES.map(c=>', $oab, 'a paleta do menu do mapa (DONO)');
        self::assertStringContainsString('<input type="date"', $oab);
        self::assertStringContainsString('body:JSON.stringify({status:sel.st,cor:sel.cor,data:dv.querySelector(\'[data-dv-data]\').value})', $oab);
        self::assertStringContainsString("'X-CSRF-Token':CFG.csrfSeccionais", $oab);
        self::assertStringContainsString("CFG.divulgar.replace('/XX/','/'+u+'/')", $oab);
        self::assertStringContainsString('window.parent.__bjSeccionaisRecarregar()', $oab);
        self::assertStringContainsString('window.__bjSeccionaisAtualizar=carregar;', $oab);
        self::assertStringContainsString("typeof w.__bjSeccionaisAtualizar === 'function'", self::bloco(), 'o painel avisa o iframe');
        self::assertStringNotContainsString('bj-mapa-seccionais-ocultas', $mapa, 'a visibilidade é do servidor');
        self::assertSame(['Material enviado', 'Reunião ou evento', 'Em negociação', 'Parceria firmada', 'Retornar depois', 'Convênio feito'], array_map(static fn (string $k): string => self::texto('master.seccionais.status.' . $k), ['env', 'vis', 'neg', 'cli', 'ret', 'sem']));
        self::assertStringContainsString("seccionais: path('master_dados_seccionais')", $tpl);
        self::assertStringContainsString("divulgar: path('master_seccional_divulgar', {uf: 'XX'})", $tpl);
        self::assertStringContainsString("csrfSeccionais: csrf_token('master_seccionais')", $tpl);
        self::assertStringContainsString("{{ catalogo_js('master.mapa_brasil', 'master.seccionais.status') }}", $tpl);
    }

    #[TestDox('painel.html.twig: a URL do GET no bloco urls e o CSS próprio; CSS com os valores do desenho em tokens')]
    public function testTemplateECss(): void
    {
        $tpl = self::arquivo('templates/master/painel.html.twig');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertStringContainsString("    seccionais: path('master_dados_seccionais'),\n", $tpl);
        self::assertStringContainsString("<link rel=\"stylesheet\" href=\"{{ asset('css/master-seccionais.css') }}\">", $tpl);
        foreach (['--sec-fundo: #fafcfd;', '--sec-titulo: #4a6373;', '--sec-azul: #0a6fa0;', '--sec-texto: #34505f;', '--sec-icone: #5b7383;', '--sec-nota: #7a8e9b;', '--sec-card-borda: #dbe6ee;', '--sec-botao-borda: #c5d2db;'] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertStringContainsString('grid-template-columns: repeat(auto-fill, minmax(34px, 1fr)); gap: 3px;', $css);
        self::assertStringContainsString('.mp-sec-card-corpo > span > .bi { color: var(--sec-icone); }', $css, 'ícone inline, como no desenho (L122)');
        self::assertStringNotContainsString('width: 16px', $css);
        self::assertMatchesRegularExpression('/\.mp-sec-chip \{\s*height: 22px;/', $css);
        self::assertStringContainsString('.mp-sec-oab-nota { display: block; font-size: 10.5px; color: var(--sec-nota); margin-top: 6px; line-height: 1.4; }', $css);
    }
}
