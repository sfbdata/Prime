<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Menu da conta e confirmação de suspender/reativar (spec perfis-1.2-contas C7 e C8; desenho 05 -
 * Perfis 1.2, linhas 624 e 735), lidos como FOLHA: não há navegador no PHPUnit, então o que se prova
 * é o contrato do public/js/master/painel.js com o desenho, com o template e com o masters.js.
 */
final class PainelMenuDaContaJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/' . $arquivo);
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

    #[TestDox('itens na ordem do desenho: Estatísticas, Histórico, Suspender (ativa) OU Reativar (suspensa ou em quarentena) e Excluir; sem limites (ainda)')]
    public function testItensDoMenu(): void
    {
        $menu = self::menuDaConta();

        $posicoes = array_map(static fn (string $rotulo): int|false => strpos($menu, $rotulo), ['Estatísticas de uso', 'Histórico de alterações', 'Suspender conta', 'Reativar conta', 'Excluir conta']);
        self::assertNotContains(false, $posicoes);
        self::assertSame($posicoes, array_values(array_unique($posicoes)));
        self::assertLessThan($posicoes[2], $posicoes[1], 'Histórico antes de Suspender/Reativar');
        self::assertLessThan($posicoes[4], $posicoes[3], 'Excluir conta por último');
        self::assertMatchesRegularExpression("/c\\.situacao === 'Ativa'\\s*\\?\\s*opMenu\\('suspender'[^\\n]*\\n\\s*:\\s*opMenu\\('reativar'/", $menu, 'Suspender só na ativa; Reativar na suspensa e na quarentena');
        self::assertStringContainsString("(c.situacao !== QUARENTENA && !c.ehDoMaster ? opMenu('excluir', ' mp-menu-excluir', 'bi-trash3', 'Excluir conta', c) : '')", $menu, 'Excluir some na conta do Master (C5) e na quarentena (C4b)');
        foreach (['Editar limite de armazenamento', 'Definir limite de usuários'] as $ausente) {
            self::assertStringNotContainsString($ausente, $menu, "{$ausente}: fora desta frente");
        }

        $css = (string) file_get_contents(self::RAIZ . 'public/css/master.css');
        self::assertStringContainsString('.mp-menu-op.mp-menu-excluir { color: #a3232b; }', $css);
        self::assertStringContainsString('.mp-menu-op.mp-menu-excluir:hover { background: #fbe7e7; }', $css);
    }

    #[TestDox('filtro "Em quarentena" (C4b): a lista padrão esconde a quarentena; a linha mostra a data da purga')]
    public function testFiltroDeQuarentena(): void
    {
        $js = self::js('painel.js');

        self::assertStringContainsString("const QUARENTENA = 'Em quarentena';", $js);
        self::assertStringContainsString(">Em quarentena</option></select>'", $js);
        self::assertStringContainsString('(S.fSit ? c.situacao === S.fSit : c.situacao !== QUARENTENA)', $js);
        self::assertStringContainsString(">apagada em ' + esc(dataBr(c.purgaEm))", $js);
        self::assertStringContainsString('D.contas.contas.filter(c => c.situacao !== QUARENTENA).sort(', $js, 'o ranking de armazenamento continua sem a quarentena');
    }

    #[TestDox('excluir: modal com o texto aprovado, botão "Excluir conta" e a palavra EXCLUIR, que vai no corpo para o servidor conferir')]
    public function testModalDeExcluir(): void
    {
        $js = self::js('painel.js');
        self::assertStringContainsString("excluir: c => ({ titulo: 'Excluir conta', icone: 'bi-exclamation-octagon', cor: '#a3232b', botao: 'Excluir conta', palavra: 'EXCLUIR', texto: 'A conta \"' + c.nome + '\" sai do ar agora e é apagada de vez em ' + D.contas.carenciaPurgaDias + ' dias. Até lá, pode ser recuperada pelo Master.' })", $js);
        self::assertStringNotContainsString('em 365 dias', $js, 'a carência vem do servidor, não fica fixa no JS');
        self::assertStringContainsString("if (tipo === 'excluir') corpo.append('confirmacao', digitado || '');", $js);
        self::assertStringContainsString('excluir: () => URLS.contaExcluir', $js);

        $masters = self::js('masters.js');
        self::assertStringContainsString('ok.disabled = !!palavraExigida;', $masters, 'com palavra, o botão nasce desabilitado');
        self::assertStringContainsString("qs('[data-mp-conf-ok]', conf).disabled = entrada.value.trim() !== palavraExigida;", $masters, 'só habilita quando a palavra bate');
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

        self::assertStringContainsString("suspender: c => ({ titulo: 'Suspender conta', icone: 'bi-pause-circle', cor: '#c08a12', botao: 'Suspender', texto: 'Os usuários de \"' + c.nome + '\" ficam sem acesso até a reativação. Nenhum dado é apagado.' })", $js);
        self::assertStringContainsString("reativar: c => ({ titulo: 'Reativar conta', icone: 'bi-play-circle', cor: '#1e7b45', botao: 'Reativar', texto: 'A conta \"' + c.nome + '\" volta a ter acesso normal.' })", $js);
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
        self::assertStringContainsString("'Não foi possível concluir a ação. Recarregue a página.'", $js);
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
