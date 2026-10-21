<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Estados que faltam clicáveis e a janela da OAB (desenho 05, Perfis 1.2, VS 21.10.26, L118, L140 e L481-495; spec
 * docs/specs/master-oab-estados-que-faltam.md), lido como FOLHA: não há navegador no PHPUnit, então o que se prova é
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

    /** O trecho do painel.js entre o comentário da janela da OAB e o renderIdades. */
    private static function bloco(): string
    {
        $js = self::arquivo('public/js/master/painel.js');
        $inicio = strpos($js, '/* ---------- Janela da OAB dos estados que faltam');
        $fim = strpos($js, 'function renderIdades()');
        self::assertNotFalse($inicio, 'o bloco da janela da OAB existe');
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    /** Corpo de uma função do bloco, do `function nome(` até a próxima função ou constante de topo. */
    private static function funcao(string $nome): string
    {
        $bloco = self::bloco();
        $inicio = strpos($bloco, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'a função ' . $nome . ' existe');
        preg_match('/\n  (?:function |const |let |\/\/|elSecao|document)/', $bloco, $m, PREG_OFFSET_CAPTURE, $inicio + 10);

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
        self::assertStringNotContainsString('<span>0</span>', $js, 'some o "0" de hoje');
        self::assertStringContainsString("const OAB_TOOLTIP = 'Ver endereços da OAB e escrever nota';", self::bloco());
        self::assertStringContainsString('<div class="mp-faltam-uf is-oab" role="button" tabindex="0" data-oab-uf="\' + esc(f.uf) + \'" title="\' + OAB_TOOLTIP + \'">', $item);
        self::assertNaOrdem(substr($item, (int) strpos($item, 'is-oab')), ['mp-faltam-ponto', 'mp-faltam-nome', '<i class="bi bi-sticky-fill" title="Tem nota"></i>', 'mp-faltam-sub', 'bi-chevron-right']);
        self::assertStringContainsString("(sec && sec.nota ? '<i class=\"bi bi-sticky-fill\"", $item, 'o ícone só com nota');
    }

    #[TestDox('L140: no Mundo o item segue sem clique (sem data-oab-uf, sem tooltip, sem seta)')]
    public function testItemNoMundo(): void
    {
        $item = self::funcao('faltamItem');
        $mundo = substr($item, 0, (int) strpos($item, 'const sec'));

        self::assertStringContainsString("if (!f.uf) return '<div class=\"mp-faltam-uf\">", $mundo);
        self::assertStringNotContainsString('data-oab-uf', $mundo);
        self::assertStringNotContainsString('bi-chevron-right', $mundo);
        self::assertStringContainsString('.map(p => ({ uf: null, r: p }))', self::arquivo('public/js/master/painel.js'));
    }

    #[TestDox('subtítulo do item (DONO): "N subseções", senão "sem endereço" sem a sede, senão vazio')]
    public function testSubtitulo(): void
    {
        self::assertStringContainsString("const faltamSub = s => (!s ? '' : s.subsecoes.length ? conta(s.subsecoes.length, 'subseção', 'subseções') : (s.endereco ? '' : 'sem endereço'));", self::bloco());
        self::assertStringContainsString("const conta = (n, um, varios) => (n === 1 ? '1 ' + um : num(n) + ' ' + varios);", self::bloco());
    }

    #[TestDox('L118: a linha da UF do Brasil é clicável (mão) e abre a mesma janela; o realce vale para todas')]
    public function testLinhaDaUf(): void
    {
        $js = self::arquivo('public/js/master/painel.js');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertStringContainsString('const oab = NM[uf] ? \' is-oab" role="button" tabindex="0" data-oab-uf="\' + esc(uf) + \'" data-oab-n="\' + n + \'"\' : \'"\';', $js);
        self::assertStringContainsString('.mp-uf-lin:hover { background: #f5fafd; }', $css);
        self::assertStringContainsString('.mp-uf-lin.is-oab { cursor: pointer; }', $css);
        self::assertStringContainsString("elSecao.addEventListener('click', e => { const alvo = e.target.closest('[data-oab-uf]'); if (alvo) oabPeloAlvo(alvo); });", self::bloco());
    }

    #[TestDox('título e subtítulo (DONO): o nome da seccional e "<UF> · sem usuários no BlueJus" para o estado que falta')]
    public function testTituloESubtitulo(): void
    {
        self::assertStringContainsString('<b id="mpOabTitulo">\' + esc(sec.nome) + \'</b><span>\' + esc(OAB.sub) + \'</span>', self::funcao('oabJanela'));
        self::assertStringContainsString("abrirOab(uf, uf + ' · ' + (n ? oabQuantos(+n) : (S.mapaQuem === 'esc' ? 'sem escritórios' : 'sem usuários')) + ' no BlueJus');", self::funcao('oabPeloAlvo'));
    }

    #[TestDox('L484-493: a caixa tem faixa, corpo e rodapé; o corpo, as três seções na ordem; o rodapé, Fechar e Salvar nota')]
    public function testArranjoDaJanela(): void
    {
        $janela = self::funcao('oabJanela');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertNaOrdem($janela, ['<div class="mp-oab-fundo" data-oab-fechar></div>', '<div class="mp-oab-centro"><div class="mp-oab" role="dialog"', '<div class="mp-oab-cab"><i class="bi bi-bank"></i>', 'bi-x-lg', "'<div class=\"mp-oab-corpo\">' + oabSede(sec) + oabSubsecoes(sec) + oabNota(sec)", '<div class="mp-oab-rodape"><button type="button" class="mp-oab-fechar" data-oab-fechar>Fechar</button>', '<i class="bi bi-check2"></i> Salvar nota</button></div></div></div>']);
        self::assertStringStartsWith("function oabSede(sec) {\n    const site", self::funcao('oabSede'));
        self::assertStringContainsString("return '<div class=\"mp-oab-sede\">", self::funcao('oabSede'));
        self::assertStringContainsString("return '<div class=\"mp-oab-subs\">", self::funcao('oabSubsecoes'));
        self::assertStringContainsString("return '<label class=\"mp-oab-nota\">NOTA EXPLICATIVA<textarea", self::funcao('oabNota'));

        foreach ([
            '.mp-oab-centro > .mp-oab {', '.mp-oab > .mp-oab-cab {', '.mp-oab > .mp-oab-corpo {', '.mp-oab > .mp-oab-rodape {',
            '.mp-oab-corpo > .mp-oab-sede {', '.mp-oab-corpo > .mp-oab-nota {', '.mp-oab-rodape > .mp-oab-fechar {', '.mp-oab-rodape > .mp-oab-salvar {',
            '.mp-oab-sede > .mp-oab-tit, .mp-oab-subs > .mp-oab-tit {', '.mp-oab-tit > .mp-oab-editar {', '.mp-oab-tit > .mp-oab-add {', '.mp-oab-subs > .mp-oab-sub {',
            '.mp-faltam-uf > .mp-faltam-ponto {', '.mp-faltam-uf > .bi-sticky-fill {', '.mp-faltam-uf > .mp-faltam-sub {', '.mp-faltam-uf > .bi-chevron-right {',
        ] as $seletor) {
            self::assertStringContainsString($seletor, $css, 'filho direto: ' . $seletor);
        }
    }

    #[TestDox('L486: SECCIONAL · SEDE com Editar, endereço (cinza sem ele), telefone, "Ver no mapa" só com endereço e "Site oficial"')]
    public function testSede(): void
    {
        $sede = self::funcao('oabSede');

        self::assertNaOrdem($sede, ['<b>SECCIONAL · SEDE</b>', '<i class="bi bi-pencil"></i> Editar</button>', 'mp-oab-end', 'bi-telephone', '<i class="bi bi-geo-alt"></i> Ver no mapa</a>', '<i class="bi bi-box-arrow-up-right"></i> Site oficial</a>']);
        self::assertStringContainsString("mapa = sec.endereco ? OAB_MAPS + encodeURIComponent(sec.endereco) : null", $sede, '"Ver no mapa" só com endereço');
        self::assertStringContainsString("(mapa ? '<a href=\"' + esc(mapa) + '\" target=\"_blank\" rel=\"noopener\">", $sede);
        self::assertStringContainsString("const OAB_MAPS = 'https://www.google.com/maps/search/?api=1&query=';", self::bloco());
        self::assertStringContainsString("const OAB_SEM_ENDERECO = 'Endereço não cadastrado. Confira no CFOAB.';", self::bloco());
        self::assertStringContainsString("(sec.endereco ? '' : ' is-vazio')", $sede);
        self::assertStringContainsString('.mp-oab-sede > .mp-oab-end.is-vazio { color: #7a8e9b; }', self::arquivo('public/css/master-seccionais.css'));
        self::assertStringContainsString("/^https:\\/\\//i.test(sec.site || '')", $sede, 'o site só sai com https://');
        self::assertStringContainsString('return secAbrirEdicao(sec);', self::funcao('oabClique'), 'o MESMO "Editar sede" do #196');
    }

    #[TestDox('L487-489: SUBSEÇÕES com "Adicionar endereço", o texto vazio literal do desenho e a linha com Remover')]
    public function testSubsecoes(): void
    {
        $subs = self::funcao('oabSubsecoes');

        self::assertNaOrdem($subs, ['<b>SUBSEÇÕES</b>', '<i class="bi bi-plus-lg"></i> Adicionar endereço</button>', 'oabFormulario()', 'mp-oab-subs-vazio', 'mp-oab-sub"><i class="bi bi-geo-alt"></i>']);
        self::assertStringContainsString("(sec.subsecoes.length ? '' : '<div class=\"mp-oab-subs-vazio\">Nenhuma subseção cadastrada. <a href=\"' + SEC_CFOAB + '\" target=\"_blank\" rel=\"noopener\">Pesquisar o endereço oficial</a> e adicionar.</div>')", $subs);
        self::assertStringContainsString('<button type="button" title="Remover" aria-label="Remover" data-oab-sub-rm="\' + esc(x.id) + \'"><i class="bi bi-trash3"></i></button>', $subs);
        self::assertStringContainsString("oabCampo('NOME', 'nome', 120) + oabCampo('ENDEREÇO', 'endereco', 255) + oabCampo('TELEFONE', 'telefone', 40)", self::funcao('oabFormulario'));
    }

    #[TestDox('L491: NOTA EXPLICATIVA com o exemplo do desenho, até 1000, e "Atualizada em dd/mm/aaaa"')]
    public function testNota(): void
    {
        $nota = self::funcao('oabNota');

        self::assertStringContainsString("const OAB_NOTA_EXEMPLO = 'Ex.: contato com a presidente da subseção, enviar material em novembro';", self::bloco());
        self::assertStringContainsString('<textarea rows="3" maxlength="1000" data-oab-nota placeholder="\' + OAB_NOTA_EXEMPLO + \'">\' + esc(texto) + \'</textarea>', $nota);
        self::assertStringContainsString("esc('Atualizada em ' + dataBr(sec.nota.atualizadaEm))", $nota);
    }

    #[TestDox('escritas: POST com o token no header nos moldes do GET; Remover pede a confirmação do painel')]
    public function testEscritas(): void
    {
        $clique = self::funcao('oabClique');

        self::assertStringContainsString("oabEnviar(SEC.dados.urlSubsecoes.replace('/XX/', '/' + OAB.uf + '/'), OAB.form,", $clique);
        self::assertStringContainsString("oabEnviar(SEC.dados.urlNota.replace('/XX/', '/' + OAB.uf + '/'), { texto: elOab.querySelector('[data-oab-nota]').value }", $clique);
        self::assertStringContainsString("SEC.dados.urlSubsecaoRemover.replace('/XX/', '/' + sec.uf + '/').replace('/0/', '/' + id + '/')", self::funcao('oabPedirRemocao'));
        self::assertStringContainsString('window.BJMasterConf.abrir({ titulo: \'Remover subseção\'', self::funcao('oabPedirRemocao'));
        self::assertStringContainsString('secPostar(url, corpo,', self::funcao('oabEnviar'), 'mesmo POST do bloco (X-CSRF-Token)');
        self::assertStringContainsString('window.__bjSeccionaisRecarregar();', self::funcao('oabEnviar'));
    }

    #[TestDox('dado do servidor só entra no HTML com esc(); sem travessão; o seletor de modos (L134) NÃO entra')]
    public function testEscapeTravessaoESemSeletor(): void
    {
        $bloco = self::bloco();

        foreach (['esc(sec.nome)', 'esc(OAB.sub)', 'esc(x.nome)', 'esc(x.endereco)', 'esc(x.telefone)', 'esc(sec.telefone)', 'esc(site)', 'esc(texto)', 'esc(f.r)', 'esc(faltamSub(sec))', 'esc(OAB.msg.texto)'] as $trecho) {
            self::assertStringContainsString($trecho, $bloco);
        }
        self::assertDoesNotMatchRegularExpression("/'\\s*\\+\\s*(sec|x|f)\\.(nome|endereco|telefone|site|r)\\b/", $bloco, 'campo concatenado cru');
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $bloco, 'sem travessão em texto novo');
        self::assertStringNotContainsString('faltamModos', self::arquivo('public/js/master/painel.js'));
        self::assertStringNotContainsString('data-faltam-modo', self::arquivo('public/js/master/painel.js'));
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
