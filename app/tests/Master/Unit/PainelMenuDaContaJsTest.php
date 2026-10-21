<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Menu da conta e confirmação de suspender/reativar (spec perfis-1.2-contas C7 e C8; desenho 05 -
 * Perfis 1.2, linhas 624 e 735), lidos como FOLHA: não há navegador no PHPUnit, então o que se prova
 * é o contrato do public/js/master/painel.js com o desenho, com o template e com o masters.js. Desde a P2b do
 * idioma (spec idioma-do-sistema), o texto mora no catálogo (master.js.menu.*, conf.*, contas.*): o teste confere a
 * chave no JS e o português no catálogo.
 */
final class PainelMenuDaContaJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/' . $arquivo);
    }

    /** Texto em português de uma chave do catálogo. */
    private static function catalogo(string $chave): string
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

    private static function menuDaConta(): string
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'const menuDaConta = c =>');
        self::assertNotFalse($inicio, 'o menu da conta existe');
        $fim = strpos($js, '</div>\';', $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('itens na ordem do desenho: limites (fora da quarentena), Estatísticas, Histórico, Suspender (ativa) OU Reativar (suspensa ou em quarentena) e Excluir')]
    public function testItensDoMenu(): void
    {
        $menu = self::menuDaConta();

        $rotulos = ['lim_arm' => 'Editar limite de armazenamento', 'lim_usu' => 'Definir limite de usuários', 'estat' => 'Estatísticas de uso', 'hist' => 'Histórico de alterações', 'suspender' => 'Suspender conta', 'reativar' => 'Reativar conta', 'excluir' => 'Excluir conta'];
        foreach ($rotulos as $chave => $rotulo) {
            self::assertSame($rotulo, self::catalogo('master.js.menu.' . $chave));
        }
        $posicoes = array_map(static fn (string $chave): int|false => strpos($menu, "T('master.js.menu." . $chave . "')"), array_keys($rotulos));
        self::assertNotContains(false, $posicoes);
        self::assertSame($posicoes, array_values(array_unique($posicoes)));
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'a ordem do desenho (linha 735)');
        self::assertMatchesRegularExpression("/c\\.situacao === ATIVA\\s*\\?\\s*opMenu\\('suspender'[^\\n]*\\n\\s*:\\s*opMenu\\('reativar'/", $menu, 'Suspender só na ativa; Reativar na suspensa e na quarentena');
        self::assertStringContainsString("(c.situacao !== QUARENTENA && !c.ehDoMaster ? opMenu('excluir', ' mp-menu-excluir', 'bi-trash3', T('master.js.menu.excluir'), c) : '')", $menu, 'Excluir some na conta do Master (C5) e na quarentena (C4b)');
        self::assertStringContainsString("(c.situacao !== QUARENTENA ? opMenu('limArm', '', 'bi-hdd', T('master.js.menu.lim_arm'), c) + opMenu('limUsu', '', 'bi-people', T('master.js.menu.lim_usu'), c) : '')", $menu, 'ícones e cor padrão do desenho; quarentena sem limites');

        $css = (string) file_get_contents(self::RAIZ . 'public/css/master.css');
        self::assertStringContainsString('.mp-menu-op.mp-menu-excluir { color: #a3232b; }', $css);
        self::assertStringContainsString('.mp-menu-op.mp-menu-excluir:hover { background: #fbe7e7; }', $css);
    }

    #[TestDox('filtro "Em quarentena" (C4b): a lista padrão esconde a quarentena; a linha mostra a data da purga')]
    public function testFiltroDeQuarentena(): void
    {
        $js = self::js('painel.js');

        self::assertStringContainsString("const ATIVA = 'Ativa', SUSPENSA = 'Suspensa', QUARENTENA = 'Em quarentena';", $js, 'o valor gravado que o servidor manda');
        self::assertStringContainsString("'>' + rotuloDaSituacao(QUARENTENA) + '</option></select>'", $js);
        self::assertSame('Em quarentena', self::catalogo('master.js.situacao.quarentena'));
        self::assertStringContainsString('(S.fSit ? c.situacao === S.fSit : c.situacao !== QUARENTENA)', $js);
        self::assertSame('apagada a partir de', self::catalogo('master.js.contas.purga'));
        self::assertStringContainsString(">' + T('master.js.contas.purga') + '<br>' + esc(dataBr(c.purgaEm))", $js, 'a purga roda às 03h do primeiro dia depois da carência: "a partir de" é exato');

        $css = (string) file_get_contents(self::RAIZ . 'public/css/master.css');
        self::assertStringContainsString('font-weight: bold; color: var(--cor); white-space: nowrap; }', $css, 'a situação não quebra');
        self::assertStringContainsString('.mp-conta-st-bloco > .mp-conta-purga { font-size: 11px; font-weight: normal; line-height: 1.3; color: #5b7383; margin-top: 2px; white-space: nowrap; }', $css);
        self::assertStringContainsString('.mp-conta-st-bloco { display: flex; flex-direction: column; align-items: flex-start; }', $css, 'a data alinhada à esquerda do texto da situação');
        self::assertStringContainsString('.mp-conta-st { display: flex; align-items: center; justify-content: center; }', $css, 'bloco centrado na célula e na altura da linha');
        self::assertStringNotContainsString('apagada em', $js);
        self::assertStringContainsString("const AI = a => (/Limite/.test(a) ? ['bi-hdd', '#0a6fa0'] : /reativada|reativado|Exclusão de conta cancelada/i.test(a) ? ['bi-play-circle', '#1e7b45'] : /suspensa|excluída", $js, 'recuperar da quarentena tem o ícone verde da reativação, testado antes do vermelho');
        self::assertStringContainsString('D.contas.contas.filter(c => c.situacao !== QUARENTENA).sort(', $js, 'o ranking de armazenamento continua sem a quarentena');
    }

    #[TestDox('excluir: modal com o texto aprovado, botão "Excluir conta" e a palavra EXCLUIR, que vai no corpo para o servidor conferir')]
    public function testModalDeExcluir(): void
    {
        $js = self::js('painel.js');
        self::assertStringContainsString("excluir: c => ({ titulo: T('master.js.conf.excluir.titulo'), icone: 'bi-exclamation-octagon', cor: '#a3232b', botao: T('master.js.conf.excluir.botao'), palavra: 'EXCLUIR', texto: T('master.js.conf.excluir.texto', { nome: c.nome, dias: D.contas.carenciaPurgaDias, papel: T('termos.master') }) })", $js, 'a palavra EXCLUIR não se traduz: o servidor confere ela');
        self::assertSame('Excluir conta', self::catalogo('master.js.conf.excluir.titulo'));
        self::assertSame('Excluir conta', self::catalogo('master.js.conf.excluir.botao'));
        self::assertSame('A conta "{nome}" sai do ar agora e é apagada de vez em {dias} dias. Até lá, pode ser recuperada pelo {papel}.', self::catalogo('master.js.conf.excluir.texto'));
        self::assertSame('Master', self::catalogo('termos.master'));
        self::assertStringNotContainsString('em 365 dias', $js, 'a carência vem do servidor, não fica fixa no JS');
        self::assertStringContainsString("if (tipo === 'excluir') corpo.append('confirmacao', digitado || '');", $js);
        self::assertStringContainsString('excluir: () => URLS.contaExcluir', $js);

        $masters = self::js('masters.js');
        self::assertStringContainsString('ok.disabled = !!palavraExigida;', $masters, 'com palavra, o botão nasce desabilitado');
        self::assertStringContainsString("qs('[data-mp-conf-ok]', conf).disabled = entrada.value.trim() !== palavraExigida;", $masters, 'só habilita quando a palavra bate');
        self::assertStringContainsString('.mp .mp-conf-ok:disabled { background: #9fb3c0; cursor: not-allowed; }', (string) file_get_contents(self::RAIZ . 'public/css/master.css'), 'desabilitado na convenção do desenho (linha 625)');
        self::assertStringContainsString('if (palavraExigida && digitado !== palavraExigida) return;', $masters);
        self::assertStringContainsString("'Digite ' + palavraExigida + ' para confirmar'", $masters);

        $twig = (string) file_get_contents(self::RAIZ . 'templates/master/painel.html.twig');
        self::assertStringContainsString("contaExcluir: path('master_conta_excluir', {id: 0})", $twig);
        self::assertStringContainsString('data-mp-conf-campo hidden', $twig);
        self::assertStringContainsString('data-mp-conf-palavra', $twig);
    }

    #[TestDox('confirmação com os textos, ícones e cores exatos da linha 624 do desenho')]
    public function testTextosDaConfirmacao(): void
    {
        $js = self::js('painel.js');

        self::assertStringContainsString("suspender: c => ({ titulo: T('master.js.conf.suspender.titulo'), icone: 'bi-pause-circle', cor: '#c08a12', botao: T('master.js.conf.suspender.botao'), texto: T('master.js.conf.suspender.texto', { nome: c.nome }) })", $js);
        self::assertStringContainsString("reativar: c => ({ titulo: T('master.js.conf.reativar.titulo'), icone: 'bi-play-circle', cor: '#1e7b45', botao: T('master.js.conf.reativar.botao'), texto: T('master.js.conf.reativar.texto', { nome: c.nome }) })", $js);
        foreach ([
            'suspender.titulo' => 'Suspender conta', 'suspender.botao' => 'Suspender', 'suspender.texto' => 'Os usuários de "{nome}" ficam sem acesso até a reativação. Nenhum dado é apagado.',
            'reativar.titulo' => 'Reativar conta', 'reativar.botao' => 'Reativar', 'reativar.texto' => 'A conta "{nome}" volta a ter acesso normal.',
        ] as $chave => $texto) {
            self::assertSame($texto, self::catalogo('master.js.conf.' . $chave), $chave);
        }
    }

    #[TestDox('envia por POST com o token da conta e usa a confirmação do masters.js; as rotas vêm do template')]
    public function testContratoComServidorEMastersJs(): void
    {
        $js = self::js('painel.js');
        self::assertStringContainsString("corpo.append('_token', c.token || '')", $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString('URLS.contaSuspender', $js);
        self::assertStringContainsString('URLS.contaReativar', $js);
        self::assertStringContainsString('window.BJMasterConf.abrir(', $js);

        self::assertStringContainsString('window.BJMasterConf = { abrir: abrirConf, fechar: fecharConf };', self::js('masters.js'));
        self::assertStringContainsString('ok.disabled = !!palavraExigida;', self::js('masters.js'), 'a confirmação destrava para a próxima ação sem recarregar (menos a que exige palavra)');

        $twig = (string) file_get_contents(self::RAIZ . 'templates/master/painel.html.twig');
        self::assertStringContainsString("contaSuspender: path('master_conta_suspender', {id: 0})", $twig);
        self::assertStringContainsString("contaReativar: path('master_conta_reativar', {id: 0})", $twig);
    }

    #[TestDox('só a resposta JSON direta com a conta é sucesso: redirecionada, sem JSON ou sem conta é erro')]
    public function testRespostaSemContaEhErro(): void
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'function enviarAcaoNaConta(');
        self::assertNotFalse($inicio);
        $envio = substr($js, $inicio, (int) strpos($js, 'function avisar(') - $inicio);

        self::assertStringContainsString('r.json().catch(() => null)', $envio, 'corpo que não é JSON vira nulo, não sucesso');
        self::assertStringContainsString("const json = !r.redirected && j !== null && typeof j === 'object';", $envio);
        self::assertStringContainsString('if (json && r.ok && j.conta) {', $envio, 'verde só com a conta no corpo');
        self::assertStringContainsString('avisar(false, json && !r.ok ? j.erro : null);', $envio);
        self::assertStringContainsString("T('master.js.erro_acao')", $js);
        self::assertSame('Não foi possível concluir a ação. Recarregue a página.', self::catalogo('master.js.erro_acao'));
    }

    #[TestDox('menu fora do card e da rolagem, em position: fixed na janela, 260px, com as coordenadas do clique ou do botão')]
    public function testMenuNaoEhCortado(): void
    {
        $js = self::js('painel.js');

        // Host do menu depois do fechamento da rolagem e do card, e o menu fora das linhas.
        self::assertStringContainsString("+ '<div id=\"mp-contas-lista\"></div></div></div></div>'\n      // O menu da conta mora FORA do card e da rolagem", $js);
        self::assertStringContainsString("+ '<div id=\"mp-menu-conta\"></div>';", $js);
        $linhas = substr($js, (int) strpos($js, 'function renderContasLista('), 4000);
        self::assertStringNotContainsString('menuDaConta(c)', substr($linhas, 0, (int) strpos($linhas, 'renderMenuDaConta();')), 'o menu não é desenhado dentro da linha');

        $css = (string) file_get_contents(self::RAIZ . 'public/css/master.css');
        self::assertMatchesRegularExpression('/\.mp-menu \{ position: fixed; z-index: 1055; top: 0; left: 0; width: 260px;/', $css);
        self::assertStringContainsString('const LARGURA_MENU = 260, MARGEM_MENU = 8;', $js);

        self::assertStringContainsString("abrirMenuDaConta(id, posDoBotao(menu))", $js, 'botão ⋮');
        self::assertStringContainsString("abrirMenuDaConta(+lin.dataset.conta, posDoClique(e))", $js, 'botão direito');
        self::assertStringContainsString('const posDoClique = e => ({ x: e.clientX, y: e.clientY,', $js);
        self::assertStringContainsString('const r = b.getBoundingClientRect();', $js);
    }

    #[TestDox('não sai da tela: sem espaço embaixo abre para cima; sem espaço à direita encosta na borda')]
    public function testMenuCabeNaTela(): void
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'function renderMenuDaConta(');
        self::assertNotFalse($inicio);
        $bloco = substr($js, $inicio, (int) strpos($js, 'const posDoBotao') - $inicio);

        self::assertStringContainsString('if (top + alto > window.innerHeight - MARGEM_MENU) top = Math.max(MARGEM_MENU, p.yAcima - alto);', $bloco);
        self::assertStringContainsString('left = Math.max(MARGEM_MENU, Math.min(left, window.innerWidth - LARGURA_MENU - MARGEM_MENU));', $bloco);
        self::assertStringContainsString("el.style.top = top + 'px';", $bloco);
        self::assertStringContainsString("el.style.left = left + 'px';", $bloco);
    }

    #[TestDox('fecha com clique fora, Esc, rolagem da página ou da tabela (captura) e redimensionar')]
    public function testMenuFecha(): void
    {
        $js = self::js('painel.js');

        self::assertStringContainsString("document.addEventListener('click', e => { if (!e.target.closest('[data-parar]')) fecharMenuDaConta(); });", $js);
        self::assertStringContainsString("document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharMenuDaConta(); });", $js);
        self::assertStringContainsString("document.addEventListener('scroll', fecharMenuDaConta, true);", $js, 'captura: pega também a rolagem horizontal da tabela');
        self::assertStringContainsString("window.addEventListener('resize', fecharMenuDaConta);", $js);
    }

    #[TestDox('sem travessão no texto novo do menu e da confirmação')]
    public function testSemTravessao(): void
    {
        $js = self::js('painel.js');
        $inicio = strpos($js, 'const menuDaConta = c =>');
        $fim = strpos($js, 'function avisar(');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        self::assertDoesNotMatchRegularExpression('/[—–]/u', substr($js, $inicio, $fim - $inicio));
    }
}
