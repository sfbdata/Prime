<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Bloco "SECCIONAIS VISÍVEIS" do mapa (desenho 05, Perfis 1.2, VS 21.10.26, L116-127; spec master-seccionais-oab),
 * lido como FOLHA: não há navegador no PHPUnit, então o que se prova é o contrato do painel.js e do mapa-brasil.js
 * com o desenho, com os templates e entre si (chave do localStorage, aviso de recarga, CSRF).
 */
final class PainelSeccionaisOabJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';
    private const NOTA = 'Clique numa seccional para ver a sede. Botão direito na lista mostra ou oculta no mapa; botão direito no mapa registra a divulgação (material enviado, reunião, parceria). O ponto colorido indica seccional já trabalhada. Fonte: Conselho Federal da OAB.';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
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

    #[TestDox('o bloco entra embaixo das UFs e acima de "Outros países", só no Brasil + Pessoas')]
    public function testGancho(): void
    {
        $js = self::arquivo('public/js/master/painel.js');

        self::assertMatchesRegularExpression("/\\+ linhas\\s*\\n\\s*\\+ \\(br && ehPes \\? blocoSeccionais\\(\\) : ''\\)\\s*\\n\\s*\\+ \\(br \\? '<div class=\"mp-uf-outros\">/", $js);
        self::assertStringContainsString('fetch(URLS.seccionais,', self::bloco());
    }

    #[TestDox('literais do desenho: cabeçalho, botão que alterna, nota e o texto quando falta o endereço')]
    public function testLiterais(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString('<b>SECCIONAIS VISÍVEIS</b>', $bloco);
        self::assertStringContainsString("(algumaOculta ? 'Mostrar todas' : 'Ocultar todas')", $bloco);
        self::assertStringContainsString("const SEC_NOTA = '" . self::NOTA . "';", $bloco);
        self::assertStringContainsString("esc(s.endereco || 'Endereço não cadastrado. Confira no CFOAB.')", $bloco);
        self::assertStringContainsString("(n === 1 ? '1 usuário' : num(n) + ' usuários')", $bloco);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $bloco, 'sem travessão em texto novo');
    }

    #[TestDox('cartão da sede na ordem do desenho: bi-bank e X, pessoas, endereço, telefone, site, CFOAB e Editar sede')]
    public function testCartao(): void
    {
        $bloco = self::bloco();
        $inicio = strpos($bloco, 'function secCartao(s)');
        self::assertNotFalse($inicio);
        $cartao = substr($bloco, $inicio, (int) strpos($bloco, 'secDivulgacoes(s.ultimas)', $inicio) - $inicio);

        $marcas = ['bi-bank', 'esc(s.nome)', 'bi-x-lg', 'bi-people', 'bi-geo-alt', 'bi-telephone', 'bi-globe2"></i>Site da seccional', 'bi-box-arrow-up-right"></i>Endereço oficial (CFOAB)', 'bi-pencil"></i> Editar sede'];
        $posicoes = array_map(static fn (string $m): int|false => strpos($cartao, $m), $marcas);
        self::assertNotContains(false, $posicoes, 'todos os itens do desenho');
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes);
        self::assertStringContainsString("const SEC_CFOAB = 'https://www.oab.org.br/institucionalconselhofederal/asiseccional';", $bloco);
        self::assertStringContainsString('target="_blank" rel="noopener"', $cartao);
        self::assertStringContainsString("/^https:\\/\\//i.test(s.site || '')", $cartao, 'o link do site só sai com https://');
    }

    #[TestDox('nenhum dado do servidor entra no HTML sem esc(): nome, endereço, telefone, site, autor e nota')]
    public function testEscape(): void
    {
        $bloco = self::bloco();

        foreach (['esc(s.nome)', 'esc(s.telefone)', 'esc(site)', 'esc(s.uf)', 'esc(d.rotulo)', "esc(d.autor || 'autor removido')", 'esc(d.nota)', 'esc(tit)'] as $trecho) {
            self::assertStringContainsString($trecho, $bloco);
        }
        self::assertDoesNotMatchRegularExpression('/\'\s*\+\s*(s|d)\.(nome|endereco|telefone|site|autor|nota|rotulo)\b/', $bloco, 'campo concatenado cru');
    }

    #[TestDox('chips: clique abre o cartão, botão direito mostra ou oculta (localStorage com try/catch), ponto pela última divulgação')]
    public function testChips(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("const SEC_CHAVE = 'bj-mapa-seccionais-ocultas';", $bloco);
        self::assertMatchesRegularExpression('/const secOcultas = \(\) => \{ try \{/', $bloco);
        self::assertMatchesRegularExpression('/const secGravarOcultas = ufs => \{ try \{ localStorage\.setItem\(SEC_CHAVE/', $bloco);
        self::assertStringContainsString("elSecao.addEventListener('contextmenu', e => {\n    const chip = e.target.closest('[data-sec-oab] [data-sec-uf]');", $bloco);
        self::assertStringContainsString("const SEC_COR = { material: 'var(--sec-material)', reuniao: 'var(--sec-reuniao)', parceria: 'var(--sec-parceria)' };", $bloco);
        self::assertStringContainsString('(s.ultima ? \'<span class="mp-sec-ponto"', $bloco);
    }

    #[TestDox('Editar sede: janela do painel (caixa/campo), POST JSON com o token no header X-CSRF-Token')]
    public function testEditarSede(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("caixa('bi-bank', 'Editar sede',", $bloco);
        self::assertStringContainsString("campo('SITE', 'type=\"url\" maxlength=\"255\" placeholder=\"https://\"", $bloco);
        self::assertStringContainsString("'X-CSRF-Token': SEC.dados.token", $bloco);
        self::assertStringContainsString("SEC.dados.urlSede.replace('/XX/', '/' + uf + '/')", $bloco);
    }

    #[TestDox('iframe: marcador no centroide (geoCentroid), mesma chave do painel, Registrar divulgação com os 3 tipos e aviso ao painel')]
    public function testIframe(): void
    {
        $mapa = self::arquivo('public/js/master/mapa-brasil.js');
        $tpl = self::arquivo('templates/master/mapa_brasil.html.twig');

        self::assertStringContainsString("KEY='bj-mapa-seccionais-ocultas'", $mapa, 'a mesma chave do painel');
        $inicio = strpos($mapa, '/* Seccionais da OAB');
        self::assertNotFalse($inicio);
        self::assertMatchesRegularExpression("/^\(\(\)=>\{[^\n]*\n\s*if\(\(new URLSearchParams\(location\.search\)\.get\('quem'\)==='esc'\?'esc':'pes'\)!=='pes'\)return;/m", substr($mapa, $inicio), 'com Escritórios, nada de marcador nem de divulgação');
        self::assertStringContainsString('proj(d3.geoCentroid(f))', $mapa);
        self::assertStringContainsString("addEventListener('storage',e=>{if(e.key===KEY)desenhar()})", $mapa);
        self::assertStringContainsString("TIPOS=[['material','Material enviado'],['reuniao','Reunião'],['parceria','Parceria']]", $mapa);
        self::assertStringContainsString('<div class="s">Registrar divulgação</div>', $mapa);
        self::assertStringContainsString("mk.querySelector('.t').after(dv)", $mapa, 'acima das cores do menu');
        self::assertStringContainsString("'X-CSRF-Token':CFG.csrfSeccionais", $mapa);
        self::assertStringContainsString("CFG.divulgar.replace('/XX/','/'+u+'/')", $mapa);
        self::assertStringContainsString('window.parent.__bjSeccionaisRecarregar()', $mapa);
        self::assertStringContainsString('window.__bjSeccionaisRecarregar = () =>', self::bloco());
        self::assertStringContainsString('maxlength="500"', $mapa);
        self::assertStringContainsString("seccionais: path('master_dados_seccionais')", $tpl);
        self::assertStringContainsString("divulgar: path('master_seccional_divulgar', {uf: 'XX'})", $tpl);
        self::assertStringContainsString("csrfSeccionais: csrf_token('master_seccionais')", $tpl);
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
