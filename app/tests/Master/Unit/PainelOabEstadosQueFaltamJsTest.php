<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    /** Texto em português (a fonte) de uma chave do catálogo: o literal do desenho mora lá desde a P2a do idioma. */
    private static function texto(string $chave): string
    {
        $no = Yaml::parseFile(self::RAIZ . 'translations/messages+intl-icu.pt_BR.yaml');
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
        self::assertStringContainsString("const OAB_TOOLTIP = T('master.js.janela_oab.dica');", self::bloco());
        self::assertSame('Ver endereços da OAB e escrever nota', self::texto('master.js.janela_oab.dica'));
        self::assertStringContainsString('<div class="mp-faltam-uf is-oab" role="button" tabindex="0" data-oab-uf="\' + esc(f.uf) + \'" title="\' + OAB_TOOLTIP + \'">', $item);
        self::assertNaOrdem(substr($item, (int) strpos($item, 'const sec')), ['mp-faltam-ponto', 'mp-faltam-nome', '<i class="bi bi-sticky-fill" title="\' + esc(T(\'master.js.janela_oab.tem_nota\')) + \'"></i>', 'mp-faltam-sub', 'bi-chevron-right']);
        self::assertSame('Tem nota', self::texto('master.js.janela_oab.tem_nota'));
        self::assertStringContainsString("(sec && sec.nota ? '<i class=\"bi bi-sticky-fill\"", $item, 'o ícone só com nota');
    }

    #[TestDox('L140: no Mundo o item tem o MESMO visual (tooltip, seta, mão e realce); o clique só mostra o aviso do painel')]
    public function testItemNoMundo(): void
    {
        $item = self::funcao('faltamItem');
        $mundo = substr($item, 0, (int) strpos($item, 'const sec'));

        self::assertStringContainsString("if (!f.uf) return '<div class=\"mp-faltam-uf is-oab\" role=\"button\" tabindex=\"0\" data-oab-mundo title=\"' + OAB_TOOLTIP + '\">", $mundo);
        self::assertStringContainsString('<i class="bi bi-chevron-right"></i></div>', $mundo);
        self::assertStringNotContainsString('data-oab-uf', $mundo, 'no Mundo não há janela');
        self::assertStringContainsString('.map(p => ({ uf: null, r: p }))', self::arquivo('public/js/master/painel.js'));
        self::assertStringContainsString("const OAB_SO_BRASIL = T('master.js.janela_oab.so_brasil');", self::bloco());
        self::assertSame('A janela da OAB vale só para os estados do Brasil.', self::texto('master.js.janela_oab.so_brasil'));
        self::assertStringContainsString("if (alvo.hasAttribute('data-oab-mundo')) return oabAvisar(OAB_SO_BRASIL);", self::funcao('oabPeloAlvo'));
        self::assertStringContainsString("e.target.closest('[data-oab-uf], [data-oab-mundo]')", self::bloco());
        self::assertStringContainsString("+ '<div class=\"mp-uf-total\"><span>' + T('master.js.mapa.total_geral') + '</span><span>' + num(ufTotal) + '</span></div>'\n      + avisoHtml()", self::arquivo('public/js/master/painel.js'), 'o aviso do painel embaixo do total');
        self::assertSame('Total geral', self::texto('master.js.mapa.total_geral'));
    }

    #[TestDox('carga das seccionais falhada: o clique tenta de novo e, se falhar outra vez, mostra o aviso de erro do painel')]
    public function testCargaFalhada(): void
    {
        self::assertStringContainsString("if (SEC.estado === 'falhou') SEC.estado = null;\n    secGarantir();", self::funcao('abrirOab'));
        self::assertStringContainsString("if (OAB.uf && !sec && SEC.estado === 'falhou') { OAB.uf = null; oabAvisar(OAB_SEM_CARGA); }", self::funcao('renderOab'));
        self::assertStringContainsString("const OAB_SEM_CARGA = T('master.js.seccionais.erro_carga');", self::bloco());
        self::assertSame('Não foi possível carregar as seccionais agora. Recarregue a página.', self::texto('master.js.seccionais.erro_carga'));
        self::assertStringContainsString('S.aviso = msg ? { ok: false, msg } : null;', self::funcao('oabAvisar'));
    }

    #[TestDox('subtítulo do item (DONO): "N subseções", senão "sem endereço" sem a sede, senão vazio')]
    public function testSubtitulo(): void
    {
        self::assertStringContainsString("const faltamSub = s => (!s ? '' : s.subsecoes.length ? conta(s.subsecoes.length, T('master.js.janela_oab.subsecao'), T('master.js.janela_oab.subsecoes')) : (s.endereco ? '' : T('master.js.janela_oab.sem_endereco')));", self::bloco());
        self::assertSame(['subseção', 'subseções', 'sem endereço'], [self::texto('master.js.janela_oab.subsecao'), self::texto('master.js.janela_oab.subsecoes'), self::texto('master.js.janela_oab.sem_endereco')]);
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
        self::assertStringContainsString("elSecao.addEventListener('click', e => { const alvo = e.target.closest('[data-oab-uf], [data-oab-mundo]'); if (alvo) oabPeloAlvo(alvo); });", self::bloco());
    }

    #[TestDox('título e subtítulo (DONO): o nome da seccional e "<UF> · sem usuários no BlueJus" para o estado que falta')]
    public function testTituloESubtitulo(): void
    {
        self::assertStringContainsString('<b id="mpOabTitulo">\' + esc(sec.nome) + \'</b><span>\' + esc(OAB.sub) + \'</span>', self::funcao('oabJanela'));
        self::assertStringContainsString("abrirOab(uf, T('master.js.janela_oab.subtitulo', { uf, quantos: oabQuantos(n) }));", self::funcao('oabPeloAlvo'));
        self::assertSame('{uf} · {quantos} no BlueJus', self::texto('master.js.janela_oab.subtitulo'));
    }

    #[TestDox('subtítulo pela linha: número comparado como número ("0" vira "sem ..."), na unidade da visão (escritórios, primários, usuários)')]
    public function testSubtituloPelaLinha(): void
    {
        $bloco = self::bloco();

        self::assertStringContainsString("const uf = alvo.dataset.oabUf, n = Number(alvo.dataset.oabN || 0);", self::funcao('oabPeloAlvo'));
        self::assertStringContainsString("const oabUnidade = () => (S.mapaQuem === 'esc' ? [T('termos.escritorio'), T('master.js.janela_oab.escritorios')] : S.mapaUsu === 'prim' ? [T('termos.primario'), T('master.js.janela_oab.primarios')] : [T('termos.usuario'), T('master.js.janela_oab.usuarios')]);", $bloco);
        self::assertSame(['escritório', 'escritórios', 'primário', 'primários', 'usuário', 'usuários'], array_map(self::texto(...), ['termos.escritorio', 'master.js.janela_oab.escritorios', 'termos.primario', 'master.js.janela_oab.primarios', 'termos.usuario', 'master.js.janela_oab.usuarios']));
        self::assertStringContainsString("const oabQuantos = n => { const [um, varios] = oabUnidade(); return n > 0 ? conta(n, um, varios) : T('master.js.janela_oab.sem', { unidade: varios }); };", $bloco);
        self::assertSame('sem {unidade}', self::texto('master.js.janela_oab.sem'));
    }

    #[TestDox('L484-493: a caixa tem faixa, corpo e rodapé; o corpo, as três seções na ordem; o rodapé, Fechar e Salvar nota')]
    public function testArranjoDaJanela(): void
    {
        $janela = self::funcao('oabJanela');
        $css = self::arquivo('public/css/master-seccionais.css');

        self::assertNaOrdem($janela, ['<div class="mp-oab-fundo" data-oab-fechar></div>', '<div class="mp-oab-centro"><div class="mp-oab" role="dialog"', '<div class="mp-oab-cab"><i class="bi bi-bank"></i>', 'bi-x-lg', "'<div class=\"mp-oab-corpo\">' + oabSede(sec) + oabSubsecoes(sec) + oabNota(sec)", '<div class="mp-oab-rodape"><button type="button" class="mp-oab-fechar" data-oab-fechar>\' + T(\'master.js.fechar\') + \'</button>', '<i class="bi bi-check2"></i> \' + T(\'master.js.janela_oab.salvar_nota\') + \'</button></div></div></div>']);
        self::assertSame(['Fechar', 'Salvar nota'], [self::texto('master.js.fechar'), self::texto('master.js.janela_oab.salvar_nota')]);
        self::assertStringStartsWith("function oabSede(sec) {\n    const site", self::funcao('oabSede'));
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

    #[TestDox('L486: SECCIONAL · SEDE com Editar, endereço (cinza sem ele), telefone, "Ver no mapa" só com endereço e "Site oficial"')]
    public function testSede(): void
    {
        $sede = self::funcao('oabSede');

        self::assertNaOrdem($sede, ["<b>' + T('master.js.janela_oab.sede') + '</b>", "<i class=\"bi bi-pencil\"></i> ' + T('master.js.janela_oab.editar') + '</button>", 'mp-oab-end', 'bi-telephone', "<i class=\"bi bi-geo-alt\"></i> ' + T('master.js.janela_oab.ver_mapa') + '</a>", "<i class=\"bi bi-box-arrow-up-right\"></i> ' + T('master.js.janela_oab.site_oficial') + '</a>"]);
        self::assertSame(['SECCIONAL · SEDE', 'Editar', 'Ver no mapa', 'Site oficial'], array_map(self::texto(...), ['master.js.janela_oab.sede', 'master.js.janela_oab.editar', 'master.js.janela_oab.ver_mapa', 'master.js.janela_oab.site_oficial']));
        self::assertStringContainsString("mapa = sec.endereco ? OAB_MAPS + encodeURIComponent(sec.endereco) : null", $sede, '"Ver no mapa" só com endereço');
        self::assertStringContainsString("(mapa ? '<a href=\"' + esc(mapa) + '\" target=\"_blank\" rel=\"noopener\">", $sede);
        self::assertStringContainsString("const OAB_MAPS = 'https://www.google.com/maps/search/?api=1&query=';", self::bloco());
        self::assertStringContainsString('const OAB_SEM_ENDERECO = SEC_SEM_ENDERECO;', self::bloco());
        self::assertSame('Endereço não cadastrado. Confira no CFOAB.', self::texto('master.js.seccionais.sem_endereco'));
        self::assertStringContainsString("(sec.endereco ? '' : ' is-vazio')", $sede);
        self::assertStringContainsString('.mp-oab-sede > .mp-oab-end.is-vazio { color: #7a8e9b; }', self::arquivo('public/css/master-seccionais.css'));
        self::assertStringContainsString("/^https:\\/\\//i.test(sec.site || '')", $sede, 'o site só sai com https://');
        self::assertStringContainsString('return secAbrirEdicao(sec);', self::funcao('oabClique'), 'o MESMO "Editar sede" do #196');
    }

    #[TestDox('L487-489: SUBSEÇÕES com "Adicionar endereço", o texto vazio literal do desenho e a linha com Remover')]
    public function testSubsecoes(): void
    {
        $subs = self::funcao('oabSubsecoes');

        self::assertNaOrdem($subs, ["<b>' + T('master.js.janela_oab.subsecoes_titulo') + '</b>", "<i class=\"bi bi-plus-lg\"></i> ' + T('master.js.janela_oab.adicionar_endereco') + '</button>", 'oabFormulario()', 'mp-oab-subs-vazio', 'mp-oab-sub"><i class="bi bi-geo-alt"></i>']);
        self::assertSame(['SUBSEÇÕES', 'Adicionar endereço'], [self::texto('master.js.janela_oab.subsecoes_titulo'), self::texto('master.js.janela_oab.adicionar_endereco')]);
        self::assertStringContainsString("(sec.subsecoes.length ? '' : '<div class=\"mp-oab-subs-vazio\">' + T('master.js.janela_oab.subs_vazio', { link: '<a href=\"' + SEC_CFOAB + '\" target=\"_blank\" rel=\"noopener\">' + T('master.js.janela_oab.pesquisar') + '</a>' }) + '</div>')", $subs);
        self::assertSame('Nenhuma subseção cadastrada. {link} e adicionar.', self::texto('master.js.janela_oab.subs_vazio'));
        self::assertSame('Pesquisar o endereço oficial', self::texto('master.js.janela_oab.pesquisar'));
        self::assertStringContainsString('<button type="button" title="\' + esc(T(\'master.js.janela_oab.remover\')) + \'" aria-label="\' + esc(T(\'master.js.janela_oab.remover\')) + \'" data-oab-sub-rm="\' + esc(x.id) + \'"><i class="bi bi-trash3"></i></button>', $subs);
        self::assertStringContainsString("oabCampo(T('master.js.campo.nome'), 'nome', 120) + oabCampo(T('master.js.campo.endereco'), 'endereco', 255) + oabCampo(T('master.js.campo.telefone'), 'telefone', 40)", self::funcao('oabFormulario'));
        self::assertSame(['NOME', 'ENDEREÇO', 'TELEFONE'], array_map(self::texto(...), ['master.js.campo.nome', 'master.js.campo.endereco', 'master.js.campo.telefone']));
    }

    #[TestDox('L491: NOTA EXPLICATIVA com o exemplo do desenho, até 1000, e "Atualizada em dd/mm/aaaa"')]
    public function testNota(): void
    {
        $nota = self::funcao('oabNota');

        self::assertStringContainsString("const OAB_NOTA_EXEMPLO = T('master.js.janela_oab.exemplo');", self::bloco());
        self::assertSame('Ex.: contato com a presidente da subseção, enviar material em novembro', self::texto('master.js.janela_oab.exemplo'));
        self::assertSame('NOTA EXPLICATIVA', self::texto('master.js.janela_oab.nota'));
        self::assertStringContainsString('<textarea rows="3" maxlength="1000" data-oab-nota placeholder="\' + OAB_NOTA_EXEMPLO + \'">\' + esc(texto) + \'</textarea>', $nota);
        self::assertStringContainsString("esc(T('master.js.janela_oab.atualizada', { data: dataBr(sec.nota.atualizadaEm) }))", $nota);
        self::assertSame('Atualizada em {data}', self::texto('master.js.janela_oab.atualizada'));
    }

    #[TestDox('escritas: POST com o token no header nos moldes do GET; Remover pede a confirmação do painel')]
    public function testEscritas(): void
    {
        $clique = self::funcao('oabClique');

        self::assertStringContainsString("oabEnviar(SEC.dados.urlSubsecoes.replace('/XX/', '/' + OAB.uf + '/'), OAB.form,", $clique);
        self::assertStringContainsString("oabEnviar(SEC.dados.urlNota.replace('/XX/', '/' + OAB.uf + '/'), { texto: elOab.querySelector('[data-oab-nota]').value }", $clique);
        self::assertStringContainsString("SEC.dados.urlSubsecaoRemover.replace('/XX/', '/' + sec.uf + '/').replace('/0/', '/' + id + '/')", self::funcao('oabPedirRemocao'));
        self::assertStringContainsString("window.BJMasterConf.abrir({ titulo: T('master.js.janela_oab.remover_titulo')", self::funcao('oabPedirRemocao'));
        self::assertSame('Remover subseção', self::texto('master.js.janela_oab.remover_titulo'));
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
