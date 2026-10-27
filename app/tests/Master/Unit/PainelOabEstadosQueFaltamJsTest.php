<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\CapitaisDosEstados;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Estados que faltam clicáveis e a janela da OAB (desenho 05, Perfis 1.2, VS 21.10.26, L118, L140 e L481-495; spec
 * docs/specs/master-oab-estados-que-faltam.md; lógica L875-905), lido como FOLHA: não há navegador no PHPUnit, então o que se prova é
 * o contrato do painel.js e do master-seccionais.css com o desenho. O arranjo se prova pelo combinador de FILHO
 * DIRETO no CSS e pela ordem em que o JS monta cada pai.
 */
final class PainelOabEstadosQueFaltamJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

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

    /** O trecho do painel.js entre o comentário da janela da OAB e o renderIdades. */
    private static function bloco(): string
    {
        $js = self::arquivo('public/js/master/painel.js');
        $inicio = strpos($js, '/* ---------- Janela da OAB (desenho 05');
        $fim = strpos($js, 'function renderIdades()');
        self::assertNotFalse($inicio, 'o bloco da janela da OAB existe');
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    /** Corpo de uma função do painel.js, do `function nome(` até a próxima função ou constante de topo. */
    private static function funcao(string $nome): string
    {
        $bloco = self::arquivo('public/js/master/painel.js');
        $inicio = strpos($bloco, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'a função ' . $nome . ' existe');
        preg_match('/\n  (?:function |const |let |\/\/|\/\*|elSecao|document|window)/', $bloco, $m, PREG_OFFSET_CAPTURE, $inicio + 10);

        return substr($bloco, $inicio, isset($m[0]) ? $m[0][1] - $inicio : null);
    }

    /** @param list<string> $marcas */
    private static function assertNaOrdem(string $texto, array $marcas): void
    {
        $posicoes = array_map(static fn (string $m): int|false => strpos($texto, $m), $marcas);
        self::assertNotContains(false, $posicoes, 'todos os itens do desenho: ' . implode(' | ', $marcas));
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'na ordem do desenho');
    }

    #[TestDox('L140: no Brasil o item é clicável (role=button) com o tooltip do desenho; ponto, nome, nota, subtítulo e seta')]
    public function testItemDosQueFaltam(): void
    {
        $js = self::arquivo('public/js/master/painel.js');
        $item = self::funcao('faltamItem');

        self::assertStringContainsString("g.itens.map(faltamItem).join('')", $js);
        self::assertStringContainsString('<div class="mp-faltam-uf is-oab" role="button" tabindex="0" data-oab-uf="\' + esc(f.uf) + \'" data-oab-cidade="\' + esc(f.cidade) + \'" title="\' + esc(T(\'master.js.janela_oab.dica\')) + \'">', $item);
        self::assertSame('Ver endereços da OAB e escrever nota', self::texto('master.js.janela_oab.dica'));
        self::assertStringContainsString("const corpo = '<span class=\"mp-faltam-ponto\"></span><span class=\"mp-faltam-nome\">' + esc(f.r) + '</span>';", $item);
        self::assertNaOrdem(substr($item, (int) strpos($item, 'const sec')), ["+ corpo", '<i class="bi bi-sticky-fill" title="\' + esc(T(\'master.js.janela_oab.tem_nota\')) + \'"></i>', 'mp-faltam-sub', 'bi-chevron-right']);
        self::assertSame('Tem nota', self::texto('master.js.janela_oab.tem_nota'));
        self::assertStringContainsString('nota = sec && oabNotaDe(sec, f.cidade)', $item, 'o ícone pela nota do estado ou da cidade');
        self::assertStringContainsString("const oabNotaDe = (sec, cidade) => (cidade ? (sec.notasCidades || {})[cidade] : sec.nota) || null;", self::bloco());
    }

    #[TestDox('L885: no Mundo o item não tem ação, nem mão, nem tooltip, nem aviso')]
    public function testItemNoMundo(): void
    {
        $item = self::funcao('faltamItem');
        $mundo = substr($item, 0, (int) strpos($item, 'const sec'));

        self::assertStringContainsString("if (!f.uf) return '<div class=\"mp-faltam-uf\">' + corpo + '<span class=\"mp-faltam-sub\"></span><i class=\"bi bi-chevron-right\"></i></div>';", $mundo);
        self::assertStringNotContainsString('is-oab', $mundo, 'sem mão nem realce');
        self::assertStringNotContainsString('title=', $mundo);
        self::assertStringContainsString("map(p => ({ uf: null, cidade: '', r: p, sub: '' }))", self::funcao('faltamGrupos'));
        self::assertStringNotContainsString('so_brasil', self::arquivo('public/js/master/painel.js'));
        self::assertStringNotContainsString('data-oab-mundo', self::arquivo('public/js/master/painel.js'));
    }

    #[TestDox('L134 e L888: pílulas Estados/Capitais só no Brasil; ativa branca em azul com sombra, inativa transparente')]
    public function testPilulas(): void
    {
        $html = self::funcao('faltamHtml');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertStringContainsString("const modos = br ? '<div class=\"mp-faltam-modos\">' + [['', T('master.js.faltam.modo_estados')], ['1', T('master.js.faltam.modo_capitais')]]", $html);
        self::assertStringContainsString("(on ? '<div class=\"mp-faltam\">' + modos + '<div class=\"mp-faltam-cob\">'", $html, 'pílulas no topo da lista');
        self::assertSame(['Estados', 'Capitais'], [self::texto('master.js.faltam.modo_estados'), self::texto('master.js.faltam.modo_capitais')]);
        self::assertStringContainsString("const fm = t.closest('[data-faltam-modo]'); if (fm) { S.faltamCid = fm.dataset.faltamModo === '1'; return renderMapa(); }", self::arquivo('public/js/master/painel.js'));
        self::assertStringContainsString('.mp-faltam > .mp-faltam-modos {', $css);
        self::assertStringContainsString('background: transparent; color: #5b7383;', $css);
        self::assertStringContainsString('.mp-faltam-modos > button.on { background: #fff; color: #0a6fa0; box-shadow: 0 1px 2px rgba(18, 36, 47, .12); }', $css);
    }

    #[TestDox('L879-882: Capitais lista as capitais sem usuário na cidade (G.C); item = capital, sub = UF; Estados sem sub; metas e cobertura "de 27"')]
    public function testCapitais(): void
    {
        $grupos = self::funcao('faltamGrupos');
        $js = self::arquivo('public/js/master/painel.js');

        self::assertStringContainsString("item = cid ? (u => ({ uf: u, cidade: CAP[u], r: CAP[u], sub: u })) : (u => ({ uf: u, cidade: '', r: NM[u], sub: '' }))", $grupos);
        self::assertStringContainsString('const falta = cid ? (u => !temCidade(G.C, u, CAP[u])) : (u => !G.A[u]);', $grupos);
        self::assertStringContainsString("tot: 27, meta: cid ? T('master.js.faltam.meta_capitais', { n: 27 }) : T('master.js.faltam.meta_estados', { n: 27 })", $grupos);
        self::assertSame(['os {n} estados', 'as {n} capitais', '{feitos} de {total} ({pct}%)'], array_map(self::texto(...), ['master.js.faltam.meta_estados', 'master.js.faltam.meta_capitais', 'master.js.faltam.cobertura_valor']));
        self::assertStringContainsString("const temCidade = (C, uf, cidade) => Object.keys(C[uf] || {}).some(k => semAcento(k) === semAcento(cidade));", $js);
        self::assertSame(27, preg_match_all("/\\b[A-Z]{2}: '[^']+'/", (string) strstr(strstr($js, 'const CAP = {'), "\n", true)), 'as 27 capitais');
        preg_match_all("/\\b([A-Z]{2}): '([^']+)'/", (string) strstr(strstr($js, 'const CAP = {'), "\n", true), $m);
        self::assertSame(CapitaisDosEstados::CAPITAIS, array_combine($m[1], $m[2]), 'a mesma lista do servidor (nota por cidade)');
        self::assertStringContainsString("abrirOab(alvo.dataset.oabUf, alvo.dataset.oabCidade);", self::funcao('mapaPeloAlvo'), 'a capital abre a janela da cidade');
    }

    #[TestDox('carga das seccionais falhada: o clique tenta de novo e, se falhar outra vez, mostra o aviso de erro do painel')]
    public function testCargaFalhada(): void
    {
        self::assertStringContainsString("if (SEC.estado === 'falhou') SEC.estado = null;\n    secGarantir();", self::funcao('abrirOab'));
        self::assertStringContainsString("if (OAB.uf && !sec && SEC.estado === 'falhou') { OAB.uf = null; avisarNoMapa(T('master.js.seccionais.erro_carga')); }", self::funcao('renderOab'));
        self::assertSame('Não foi possível carregar as seccionais agora. Recarregue a página.', self::texto('master.js.seccionais.erro_carga'));
        self::assertStringContainsString('S.aviso = msg ? { ok: false, msg } : null;', self::funcao('avisarNoMapa'));
    }

    #[TestDox('L889: título (estado, ou "<Cidade> · <UF>") e subtítulo do desenho')]
    public function testTituloESubtitulo(): void
    {
        $janela = self::funcao('oabJanela');

        self::assertStringContainsString("const tit = OAB.cidade ? T('master.js.janela_oab.titulo_cidade', { cidade: OAB.cidade, uf: sec.uf }) : secEstado(sec);", $janela);
        self::assertStringContainsString("const sub = OAB.cidade ? T('master.js.janela_oab.sub_cidade', { uf: sec.uf }) : T('master.js.janela_oab.sub_estado', { uf: sec.uf });", $janela);
        self::assertStringContainsString('<b id="mpOabTitulo">\' + esc(tit) + \'</b><span>\' + esc(sub) + \'</span>', $janela);
        self::assertSame(['{cidade} · {uf}', 'Seccional OAB/{uf} e subseções cadastradas', 'Subseção da OAB e seccional OAB/{uf}'], array_map(self::texto(...), ['master.js.janela_oab.titulo_cidade', 'master.js.janela_oab.sub_estado', 'master.js.janela_oab.sub_cidade']));
    }

    #[TestDox('L484-493: a caixa tem faixa, corpo e rodapé; o corpo, as três seções na ordem; o rodapé, Fechar e Salvar nota')]
    public function testArranjoDaJanela(): void
    {
        $janela = self::funcao('oabJanela');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertNaOrdem($janela, ['<div class="mp-oab-fundo" data-oab-fechar></div>', '<div class="mp-oab-centro"><div class="mp-oab" role="dialog"', '<div class="mp-oab-cab"><i class="bi bi-bank"></i>', 'bi-x-lg', "'<div class=\"mp-oab-corpo\">' + oabSede(sec) + oabSubsecoes(sec) + oabNota(sec)", '<div class="mp-oab-rodape"><button type="button" class="mp-oab-fechar" data-oab-fechar>\' + T(\'master.js.fechar\') + \'</button>', '<i class="bi bi-check2"></i> \' + T(\'master.js.janela_oab.salvar_nota\') + \'</button></div></div></div>']);
        self::assertSame(['Fechar', 'Salvar nota'], [self::texto('master.js.fechar'), self::texto('master.js.janela_oab.salvar_nota')]);
        self::assertStringContainsString("return '<div class=\"mp-oab-sede\">", self::funcao('oabSede'));
        self::assertStringContainsString("return '<div class=\"mp-oab-subs\">", self::funcao('oabSubsecoes'));
        self::assertStringContainsString("return '<label class=\"mp-oab-nota\">' + T('master.js.janela_oab.nota') + '<textarea", self::funcao('oabNota'));

        foreach ([
            '.mp-oab-centro > .mp-oab {', '.mp-oab > .mp-oab-cab {', '.mp-oab > .mp-oab-corpo {', '.mp-oab > .mp-oab-rodape {',
            '.mp-oab-corpo > .mp-oab-sede {', '.mp-oab-corpo > .mp-oab-nota {', '.mp-oab-rodape > .mp-oab-fechar {', '.mp-oab-rodape > .mp-oab-salvar {',
            '.mp-oab-sede > .mp-oab-tit, .mp-oab-subs > .mp-oab-tit {', '.mp-oab-tit > .mp-oab-editar {', '.mp-oab-tit > .mp-oab-add {', '.mp-oab-subs > .mp-oab-sub {',
            '.mp-faltam-uf > .mp-faltam-ponto {', '.mp-faltam-uf > .bi-sticky-fill {', '.mp-faltam-uf > .mp-faltam-sub {', '.mp-faltam-uf > .bi-chevron-right {',
        ] as $seletor) {
            self::assertStringContainsString($seletor, $css, 'filho direto: ' . $seletor);
        }
    }

    #[TestDox('L486 e L890: SECCIONAL · SEDE com Editar (rótulos da janela), endereço (#243845, ou "Endereço ainda não cadastrado" em #9aabb7), telefone, mapa e site')]
    public function testSede(): void
    {
        $sede = self::funcao('oabSede');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertNaOrdem($sede, ["<b>' + T('master.js.janela_oab.sede') + '</b>", "<i class=\"bi bi-pencil\"></i> ' + T('master.js.janela_oab.editar') + '</button>", 'mp-oab-end', 'bi-telephone', "<i class=\"bi bi-geo-alt\"></i> ' + T('master.js.janela_oab.ver_mapa') + '</a>", "<i class=\"bi bi-box-arrow-up-right\"></i> ' + T('master.js.janela_oab.site_oficial') + '</a>"]);
        self::assertSame(['SECCIONAL · SEDE', 'Editar', 'Ver no mapa', 'Site oficial', 'Endereço ainda não cadastrado'], array_map(self::texto(...), ['master.js.janela_oab.sede', 'master.js.janela_oab.editar', 'master.js.janela_oab.ver_mapa', 'master.js.janela_oab.site_oficial', 'master.js.janela_oab.sem_endereco_sede']));
        self::assertStringContainsString("esc(sec.endereco || T('master.js.janela_oab.sem_endereco_sede'))", $sede);
        self::assertStringContainsString("(sec.endereco ? '' : ' is-vazio')", $sede);
        self::assertStringContainsString('.mp-oab-sede > .mp-oab-end { font-size: 13.5px; color: #243845; }', $css);
        self::assertStringContainsString('.mp-oab-sede > .mp-oab-end.is-vazio { color: #9aabb7; }', $css);
        self::assertStringContainsString('mapa = sec.endereco ? OAB_MAPS + encodeURIComponent(sec.endereco) : null', $sede, '"Ver no mapa" só com endereço');
        self::assertStringContainsString('site = secSite(sec.site)', $sede, 'site http ou https');
        self::assertStringContainsString("return secAbrirEdicao(sec, 'janela');", self::funcao('oabClique'), 'o MESMO "Editar sede", com os rótulos da janela');
    }

    #[TestDox('L893-895: SUBSEÇÕES por cidade, vazio com a busca do Google, formulário com cidade (só no estado), nome preenchido, endereço e telefone')]
    public function testSubsecoes(): void
    {
        $subs = self::funcao('oabSubsecoes');
        $form = self::funcao('oabFormulario');

        self::assertNaOrdem($subs, ["<b>' + T('master.js.janela_oab.subsecoes_titulo') + '</b>", "<i class=\"bi bi-plus-lg\"></i> ' + T('master.js.janela_oab.adicionar_endereco') + '</button>", 'oabFormulario()', 'mp-oab-subs-vazio', 'mp-oab-sub"><i class="bi bi-geo-alt"></i>']);
        self::assertStringContainsString('const lista = OAB.cidade ? sec.subsecoes.filter(x => semAcento(x.cidade) === semAcento(OAB.cidade)) : sec.subsecoes;', $subs);
        self::assertStringContainsString("const OAB_BUSCA = 'https://www.google.com/search?q=';", self::bloco());
        self::assertStringContainsString("'<a href=\"' + esc(OAB_BUSCA + encodeURIComponent(busca)) + '\" target=\"_blank\" rel=\"noopener\">'", $subs);
        self::assertSame(['Nenhuma subseção cadastrada. {link} e adicionar.', 'Pesquisar o endereço oficial', 'OAB seccional {estado} endereço', 'OAB subseção {cidade} {estado} endereço'], array_map(self::texto(...), ['master.js.janela_oab.subs_vazio', 'master.js.janela_oab.pesquisar', 'master.js.janela_oab.busca_estado', 'master.js.janela_oab.busca_cidade']));
        self::assertStringContainsString("(OAB.cidade ? '' : oabCampo(T('master.js.janela_oab.campo_cidade'), 'cidade', 120))", $form);
        self::assertNaOrdem($form, ["'master.js.janela_oab.campo_cidade'", "'master.js.janela_oab.campo_nome'", "'master.js.janela_oab.campo_endereco_completo'", "'master.js.janela_oab.campo_telefone'"]);
        self::assertSame(['Cidade da subseção', 'Nome (ex.: Subseção de {cidade})', 'Subseção de {cidade}', 'Endereço completo', 'Telefone (opcional)'], array_map(self::texto(...), ['master.js.janela_oab.campo_cidade', 'master.js.janela_oab.campo_nome', 'master.js.janela_oab.nome_padrao', 'master.js.janela_oab.campo_endereco_completo', 'master.js.janela_oab.campo_telefone']));
        self::assertStringContainsString("nome: OAB.cidade ? T('master.js.janela_oab.nome_padrao', { cidade: OAB.cidade }) : ''", self::funcao('oabAbrirFormulario'));
        self::assertStringContainsString("if (OAB.form.nomeAuto && nome) { OAB.form.nome = cidade ? T('master.js.janela_oab.nome_padrao', { cidade }) : ''", self::funcao('oabDigitou'));
        self::assertStringContainsString('<button type="button" title="\' + esc(T(\'master.js.janela_oab.remover\')) + \'" aria-label="\' + esc(T(\'master.js.janela_oab.remover\')) + \'" data-oab-sub-rm="\' + esc(x.id) + \'"><i class="bi bi-trash3"></i></button>', $subs);
    }

    #[TestDox('L896: NOTA EXPLICATIVA do estado ou da cidade, até 1000, e "Nota salva em dd/mm/aaaa, hh:mm" no fuso de Brasília')]
    public function testNota(): void
    {
        $nota = self::funcao('oabNota');

        self::assertSame('Ex.: contato com a presidente da subseção, enviar material em novembro', self::texto('master.js.janela_oab.exemplo'));
        self::assertSame('NOTA EXPLICATIVA', self::texto('master.js.janela_oab.nota'));
        self::assertStringContainsString("const nota = oabNotaDe(sec, OAB.cidade)", $nota);
        self::assertStringContainsString('<textarea rows="3" maxlength="1000" data-oab-nota placeholder="\' + esc(T(\'master.js.janela_oab.exemplo\')) + \'">\' + esc(texto) + \'</textarea>', $nota);
        self::assertStringContainsString("esc(T('master.js.janela_oab.nota_salva', { data: oabDataHora(nota.atualizadaEm) }))", $nota);
        self::assertSame('Nota salva em {data}', self::texto('master.js.janela_oab.nota_salva'));
        self::assertStringContainsString("const OAB_FUSO = 'America/Sao_Paulo';", self::bloco());
        self::assertStringContainsString("new Intl.DateTimeFormat('pt-BR', { timeZone: OAB_FUSO, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })", self::bloco());
    }

    #[TestDox('escritas: POST com o token no header nos moldes do GET (subseção com cidade, nota com cidade); "Salvar nota" fecha a janela; Remover confirma')]
    public function testEscritas(): void
    {
        $clique = self::funcao('oabClique');

        self::assertStringContainsString("oabEnviar(SEC.dados.urlSubsecoes.replace('/XX/', '/' + OAB.uf + '/'), oabCorpoDaSubsecao(), () => { OAB.form = null; }, false)", $clique);
        self::assertStringContainsString("cidade: OAB.cidade || OAB.form.cidade", self::bloco());
        self::assertStringContainsString("oabEnviar(SEC.dados.urlNota.replace('/XX/', '/' + OAB.uf + '/'), { texto: elOab.querySelector('[data-oab-nota]').value, cidade: OAB.cidade }, () => {}, true)", $clique);
        self::assertStringContainsString('if (fechar) fecharOab(); else renderOab();', self::funcao('oabEnviar'));
        self::assertStringContainsString("SEC.dados.urlSubsecaoRemover.replace('/XX/', '/' + sec.uf + '/').replace('/0/', '/' + id + '/')", self::funcao('oabPedirRemocao'));
        self::assertStringContainsString("window.BJMasterConf.abrir({ titulo: T('master.js.janela_oab.remover_titulo')", self::funcao('oabPedirRemocao'));
        self::assertSame('Remover este endereço?', self::texto('master.js.janela_oab.remover_titulo'));
        self::assertStringContainsString('secPostar(url, corpo,', self::funcao('oabEnviar'), 'mesmo POST do bloco (X-CSRF-Token)');
        self::assertStringContainsString('window.__bjSeccionaisRecarregar();', self::funcao('oabEnviar'));
    }

    #[TestDox('dado do servidor só entra no HTML com esc(); sem travessão')]
    public function testEscapeETravessao(): void
    {
        $bloco = self::bloco();

        foreach (['esc(tit)', 'esc(sub)', 'esc(x.nome)', 'esc(x.endereco)', 'esc(x.telefone)', 'esc(sec.telefone)', 'esc(site)', 'esc(texto)', 'esc(f.r)', 'esc(f.sub)', 'esc(OAB.msg.texto)'] as $trecho) {
            self::assertStringContainsString($trecho, $bloco);
        }
        self::assertDoesNotMatchRegularExpression("/'\\s*\\+\\s*(sec|x|f)\\.(nome|endereco|telefone|site|r|sub|cidade)\\b/", $bloco, 'campo concatenado cru');
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $bloco, 'sem travessão em texto novo');
    }

    #[TestDox('pilha: a janela no z-index do desenho (9400/9401) e o Editar sede e a confirmação por cima dela')]
    public function testPilha(): void
    {
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertStringContainsString('.mp-oab-fundo { position: fixed; inset: 0; z-index: 9400; background: rgba(18, 36, 47, .35); }', $css);
        self::assertStringContainsString('.mp-oab-centro { position: fixed; inset: 0; z-index: 9401;', $css);
        self::assertStringContainsString('.mp.mp-oab-aberta .mp-lim-fundo, .mp.mp-oab-aberta .mp-conf-fundo { z-index: 9402; }', $css);
        self::assertStringContainsString("raiz.classList.toggle('mp-oab-aberta', !!sec);", self::funcao('renderOab'));
        self::assertStringContainsString('}, true);', self::bloco(), 'Esc em captura: fecha a janela só sem nada por cima');
    }
}
