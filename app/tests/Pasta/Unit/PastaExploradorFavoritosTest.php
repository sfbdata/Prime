<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L6-UI da aba Documentos: favoritos na tela (DOC-23; desenho 02 - EXPEDIENTES 1.2.3,
 * `expFavs`/`expFavAlt`/`favLinha` L4440-4442, L4715, L4743, L4820).
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest): sem harness de navegador na suíte,
 * o que dá para travar é o texto do contrato com o backend (`urlFavorito`/`csrfFavorito`, corpo
 * `{_token, tipo, alvoId, marcado}`), a alternância acessível (aria-pressed), o rollback no erro,
 * o limite de pedidos paralelos e a guarda do modo Manual — a ordem gravada pelo /reordenar não
 * pode carregar o "favorito no topo", que é só da tela de quem marcou.
 */
final class PastaExploradorFavoritosTest extends TestCase
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

    /** O corpo de `function $nome(…) {` até o fechamento no mesmo recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('contrato com o backend: urlFavorito/csrfFavorito do #pexDados; corpo {_token, tipo documento|pasta, alvoId, marcado}; só ok + marcado do servidor contam')]
    public function testContratoComOBackend(): void
    {
        $js = $this->js();

        self::assertStringContainsString("urlFavorito:         dados.urlFavorito || '',", $js);
        self::assertStringContainsString("csrfFavorito:        dados.csrfFavorito || '',", $js);

        $pedir = $this->funcao('pedirFavorito');
        self::assertStringContainsString('postJson(cfg.urlFavorito, {', $pedir);
        self::assertStringContainsString('_token: cfg.csrfFavorito,', $pedir, 'o token é o da pasta (pex_favorito_<id>), não o do lote');
        self::assertStringContainsString("tipo: it.tipo === 'pasta' ? 'pasta' : 'documento',", $pedir, 'o backend só aceita documento|pasta');
        self::assertStringContainsString('alvoId: it.dado.id,', $pedir);
        self::assertStringContainsString('marcado: marcar,', $pedir, 'o pedido diz o estado desejado (idempotente)');
        self::assertStringContainsString('if (!res.ok || !res.j || !res.j.ok) throw new Error(', $pedir);
        self::assertStringContainsString('return res.j.marcado === true;', $pedir, 'o estado final é o que o servidor devolve');
        self::assertStringNotContainsString('cfg.csrfLote', $pedir);

        // Sem rota/token na página, nada de pedido às cegas.
        self::assertStringContainsString("if (!cfg.urlFavorito || !cfg.csrfFavorito) { toastErro(", $this->funcao('alternarFavoritos'));
    }

    #[TestDox('estrela: <button> de alternância com aria-pressed e rótulo fixo; título e ícone do desenho; em toda linha/cartão (menos a linha provisória)')]
    public function testEstrelaAcessivel(): void
    {
        $js     = $this->js();
        $botao  = $this->funcao('botaoFavorito');

        self::assertStringContainsString("type: 'button',", $botao);
        self::assertStringContainsString("'aria-pressed': on ? 'true' : 'false',", $botao);
        self::assertStringContainsString("'aria-label': 'Favorito',", $botao, 'com aria-pressed o rótulo não muda — muda o estado');
        self::assertStringContainsString("title: on ? 'Tirar dos favoritos' : 'Marcar como favorito',", $botao, 'títulos do desenho (dc L4743)');
        self::assertStringContainsString("icone(on ? 'bi-star-fill' : 'bi-star')", $botao);

        // Na célula do nome, ANTES do ícone (dc L2246) — o montarItem é o mesmo para os oito modos.
        $montar = $this->funcao('montarItem');
        $estrela = strpos($montar, 'favorito === null ? null : botaoFavorito(favorito),');
        $ico     = strpos($montar, "h('span', { class: 'pex-ico' }");
        self::assertNotFalse($estrela);
        self::assertNotFalse($ico);
        self::assertLessThan($ico, $estrela);
        self::assertStringContainsString("ico: nome.querySelector('.pex-ico')", $montar, 'o ícone do arquivo não pode cair dentro da estrela');
        self::assertStringContainsString('p.id ? !!p.favorito : null', $js, 'a nova pasta provisória (id 0) não tem estrela');
        self::assertStringContainsString('!!a.favorito', $js);
        self::assertStringNotContainsString('innerHTML', $js);
    }

    #[TestDox('clique na estrela: alterna sem mexer na seleção; Enter/Espaço/duplo clique/toque longo na estrela não abrem o item nem o menu')]
    public function testCliqueNaEstrelaNaoVazaParaALinha(): void
    {
        $js = $this->js();

        $clique = strpos($js, "el.lista.addEventListener('click', function (e) {");
        self::assertNotFalse($clique);
        $fav     = strpos($js, "const btnFav = e.target.closest('.pex-fav');", $clique);
        $selecao = strpos($js, 'else selecionar(chave);', $clique);
        self::assertNotFalse($fav);
        self::assertNotFalse($selecao);
        self::assertLessThan($selecao, $fav, 'a estrela é tratada antes de qualquer seleção');
        self::assertStringContainsString("alternarFavoritos([it], !ehFavorito(it), { focar: chaveDe(it), rolar: e.detail === 0 });", $js);

        self::assertStringContainsString("if (e.target.closest('.pex-ren, .pex-menu, .pex-fav')) return;", $js, 'duplo clique na estrela não abre');
        self::assertStringContainsString("if ((k === 'Enter' || k === ' ') && e.target.closest && e.target.closest('.pex-fav')) return;", $js, 'Enter/Espaço são do botão');
        self::assertStringContainsString("e.target.closest('.pex-ren, .pex-menu, .pex-fav')) return;\n            cancelarToqueLongo();", $js, 'toque longo na estrela não abre o menu');
    }

    #[TestDox('otimista com rollback: a tela muda antes do pedido; quem falhou volta ao estado anterior com toast de erro; alvo em voo não aceita outro clique')]
    public function testOtimistaComRollback(): void
    {
        $corpo = $this->funcao('alternarFavoritos');

        $otimista = strpos($corpo, 'alvos.forEach(function (it) { favoritosEmVoo.add(chaveDe(it)); it.dado.favorito = marcar; });');
        $render   = strpos($corpo, 'renderizarFavoritos(opts);');
        $pedido   = strpos($corpo, 'pedirFavorito(it, marcar)');
        self::assertNotFalse($otimista);
        self::assertNotFalse($render);
        self::assertNotFalse($pedido);
        self::assertLessThan($pedido, $otimista, 'a estrela muda antes da resposta');
        self::assertLessThan($pedido, $render);

        self::assertStringContainsString('falhas.forEach(function (it) { it.dado.favorito = !marcar; });', $corpo, 'rollback só de quem falhou');
        self::assertStringContainsString('if (falhas.length || divergiu) renderizarFavoritos(opts);', $corpo);
        self::assertStringContainsString('toastErro(', $corpo);
        self::assertStringContainsString('!favoritosEmVoo.has(chaveDe(it))', $corpo, 'dois pedidos cruzados do mesmo alvo poderiam gravar o contrário do que a tela mostra');
        self::assertStringContainsString('favoritosEmVoo.delete(chaveDe(it));', $corpo);
        self::assertStringContainsString('ehFavorito(it) !== marcar', $corpo, 'só pede para quem muda de estado');
    }

    #[TestDox('vários: no máximo 4 pedidos em paralelo, sob o mesmo teto do lote; toast do desenho no singular e no plural')]
    public function testVariosEmParaleloLimitado(): void
    {
        $js    = $this->js();
        $corpo = $this->funcao('alternarFavoritos');

        self::assertStringContainsString('const FAVORITO_PARALELO = 4;', $js);
        self::assertStringContainsString('for (let i = 0; i < Math.min(FAVORITO_PARALELO, alvos.length); i++) trabalhadores.push(proximo());', $corpo);
        self::assertStringContainsString('return Promise.all(trabalhadores).then(function () {', $corpo);
        self::assertStringContainsString('if (acimaDoTeto(alvos.length)) return Promise.resolve(false);', $corpo);
        // Textos do desenho (dc L4442).
        self::assertStringContainsString("'★ ' + nome + (alvos.length === 1 ? ' foi para o topo da lista' : ' foram para o topo da lista')", $corpo);
        self::assertStringContainsString("nome + (alvos.length === 1 ? ' saiu dos favoritos' : ' saíram dos favoritos')", $corpo);
    }

    #[TestDox('menu de contexto: um item e vários; rótulos e ícones do desenho; com vários, "Tirar" só quando todos já são favoritos')]
    public function testItemDoMenu(): void
    {
        $op = $this->funcao('opFavorito');

        self::assertStringContainsString('const todos = itens.length > 0 && itens.every(ehFavorito);', $op);
        self::assertStringContainsString("todos ? 'Tirar dos favoritos' : (itens.length > 1 ? 'Marcar como favoritos' : 'Marcar como favorito')", $op);
        self::assertStringContainsString("todos ? 'bi-star' : 'bi-star-fill'", $op, 'ícones do desenho (dc L4820)');
        self::assertStringContainsString('alternarFavoritos(itens, !todos);', $op);

        $menu = $this->funcao('opcoesDoMenu');
        self::assertSame(1, substr_count($menu, 'opFavorito(sel),'), 'menu de vários');
        self::assertSame(1, substr_count($menu, 'opFavorito([alvo]),'), 'menu de um item');
    }

    #[TestDox('ordem: favoritos sobem ao topo DO GRUPO (pastas antes de arquivos) em qualquer classificação, por partição estável')]
    public function testFavoritosNoTopoDoGrupo(): void
    {
        $js = $this->js();

        $sortArq = strpos($js, 'categoriaRotulo: a.categoriaRotulo, dado: a }; }).sort(cmp);');
        $topoPs  = strpos($js, 'ps = favoritosNoTopo(ps);');
        $topoAs  = strpos($js, 'as = favoritosNoTopo(as);');
        $junta   = strpos($js, 'return ps.concat(as);');
        foreach ([$sortArq, $topoPs, $topoAs, $junta] as $pos) {
            self::assertNotFalse($pos);
        }
        self::assertLessThan($topoPs, $sortArq, 'o topo vem DEPOIS da classificação (dc L4715)');
        self::assertLessThan($junta, $topoAs, 'cada grupo separado: pastas continuam antes dos arquivos');
        self::assertStringContainsString(
            'return lista.filter(ehFavorito).concat(lista.filter(function (it) { return !ehFavorito(it); }));',
            $this->funcao('favoritosNoTopo')
        );
    }

    #[TestDox('guarda do Manual: o /reordenar recebe a ordem SEM o efeito "favorito no topo" — cada um volta para a sua faixa da ordem manual de antes')]
    public function testGuardaDoManual(): void
    {
        $js = $this->js();

        self::assertStringContainsString(
            "const ids = ordemManualSemOTopo(tipo, Array.prototype.slice.call(el.lista.querySelectorAll('.pex-item[data-pex-tipo=\"' + tipo + '\"]'))",
            $js,
            'o onEnd do Sortable não grava a ordem do DOM crua'
        );
        $guarda = $this->funcao('ordemManualSemOTopo');
        self::assertStringContainsString('const cmp = comparador();', $guarda, 'a ordem de antes é a do Manual (ordem, nome)');
        self::assertStringContainsString('const antes = idsNaTela.slice().sort(function (a, b) { return cmp(dadoDe(a), dadoDe(b)); });', $guarda);
        self::assertStringContainsString('return antes.map(function (id) { return fav(id) ? favoritos.shift() : demais.shift(); });', $guarda);
        // E o Sortable continua só com o nível inteiro na tela (L2): a guarda não troca essa.
        self::assertStringContainsString("return !!window.Sortable && classificar.chave === 'manual' && normalizar(busca) === '' && filtroTipo === 'todos';", $js);
    }

    #[TestDox('CSS: 20×20, ícone 13px, desligada #b4c2cc a .55, ligada #f0b400, hover #e0a400 (dc L4743); par no tema escuro; canto do cartão na grade; alvo maior no toque')]
    public function testCssDaEstrela(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/--pex-fav-off:\s+#b4c2cc;/', $css);
        self::assertMatchesRegularExpression('/--pex-fav-on:\s+#f0b400;/', $css);
        self::assertMatchesRegularExpression('/--pex-fav-hover:\s+#e0a400;/', $css);
        self::assertSame(2, substr_count($css, '--pex-fav-off:'), 'um no claro, um no escuro');
        self::assertSame(2, substr_count($css, '--pex-fav-on:'));
        self::assertSame(2, substr_count($css, '--pex-fav-hover:'));

        $ini   = strpos($css, "\n.pex-fav {");
        self::assertNotFalse($ini);
        $bloco = substr($css, $ini, strpos($css, '}', $ini) - $ini);
        self::assertStringContainsString('width: 20px;', $bloco);
        self::assertStringContainsString('height: 20px;', $bloco);
        self::assertStringContainsString('font-size: 13px;', $bloco);
        self::assertStringContainsString('color: var(--pex-fav-off);', $bloco);
        self::assertStringContainsString('opacity: .55;', $bloco);
        self::assertStringContainsString('.pex-fav.pex-fav--on { color: var(--pex-fav-on); opacity: 1; }', $css);
        self::assertStringContainsString('.pex--grade .pex-fav { position: absolute; top: 4px; left: 4px; z-index: 2; }', $css);
        self::assertStringContainsString('.pex-fav:focus-visible {', $css, 'foco visível pelo teclado');
    }
}
