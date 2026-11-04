<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote L7-UI da aba Documentos: o "Desfazer" do toast e o modal da lixeira — contratos do
 * `pasta-explorador.js` que nenhum teste de HTML vê, porque nascem no JS a partir da resposta
 * do excluir-lote e do GET da lixeira.
 *
 * Teste de FOLHA (precedente: PastaExploradorInteracaoTest). O que se trava: o Desfazer manda
 * os ids que o SERVIDOR devolveu com o csrfLote, o confirm() continua antes do pedido (S-3), a
 * lista da lixeira nasce sem HTML em string, o agrupamento usa `paiNaLixeira`/`secaoNaLixeira`,
 * e a lápide da Pasta inteira não ganha botão de escrita.
 *
 * Desde 03/11/2026 o modal da lixeira mora em `pasta-lixeira.js` (a Lixeira do Expediente abre o mesmo):
 * o que é do modal se lê de lá (`lix()`/`funcaoLix()`); o que é da aba (rotas do #pexDados, rótulo,
 * foco de volta, recarregar ao fechar) continua no explorador.
 */
final class PastaExploradorLixeiraTest extends TestCase
{
    use TextoDosScriptsDaPasta;

    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-explorador.css';
    private const LIX = __DIR__ . '/../../../public/js/pasta-lixeira.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function lix(): string
    {
        return (string) file_get_contents(self::LIX);
    }

    /** `funcao()` no pasta-lixeira.js (o modal). */
    private function funcaoLix(string $nome): string
    {
        return $this->funcao($nome, $this->lix());
    }

    /** O corpo de `function $nome(…) {` até o fechamento no mesmo recuo de 4 espaços. */
    private function funcao(string $nome, ?string $js = null): string
    {
        $js ??= $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('as rotas vêm do #pexDados (urlRestaurar/urlLixeira) e a lápide da Pasta vem do data-pasta-excluida')]
    public function testConfiguracao(): void
    {
        $js = $this->js();

        self::assertStringContainsString("urlRestaurar:        dados.urlRestaurar || '',", $js);
        self::assertStringContainsString("urlLixeira:          dados.urlLixeira || '',", $js);
        self::assertStringContainsString("pastaExcluida:       raiz.dataset.pastaExcluida === '1',", $js);
        self::assertStringContainsString("toastDesfazer:  document.getElementById('pexToastDesfazer'),", $js);
    }

    #[TestDox('Desfazer: chama urlRestaurar com o csrfLote e os ids DA RESPOSTA do excluir-lote (exige lixeira: true), nunca os que a tela mandou')]
    public function testDesfazerUsaOsIdsDaResposta(): void
    {
        $excluir = $this->funcao('excluirItens');
        // Confirmação mantida (S-3; janela da tela desde a E7), ANTES do pedido: o pedido mora na
        // função que só roda no "sim".
        self::assertLessThan(strpos($excluir, 'postJson('), strpos($excluir, 'window.confirmarUIPs(pergunta, true)'));
        self::assertLessThan(strpos($excluir, 'postJson('), strpos($excluir, 'function excluirConfirmado() {'));
        // O que sai da memória é guardado (o objeto inteiro), não recriado depois.
        self::assertStringContainsString('saiu.arquivos.unshift(arquivos.splice(i, 1)[0]);', $excluir);
        self::assertStringContainsString('saiu.pastas.unshift(pastas.splice(i, 1)[0]);', $excluir);

        $de = $this->funcao('desfazerDe');
        self::assertStringContainsString("if (cfg.pastaExcluida || !cfg.urlRestaurar || !resposta || resposta.lixeira !== true || !resposta.ids) return null;", $de);
        self::assertStringContainsString('resposta.ids.documentos.map(Number)', $de);
        self::assertStringContainsString('resposta.ids.secoes.map(Number)', $de);
        self::assertStringNotContainsString('lote.', $de, 'os ids são os do servidor');

        $desfazer = $this->funcao('desfazerExclusao');
        self::assertStringContainsString('postJson(cfg.urlRestaurar, { _token: cfg.csrfLote, documentos: ids.documentos, secoes: ids.secoes })', $desfazer);
        // Volta da memória SÓ depois do ok do servidor e conferindo as contagens; senão recarrega.
        self::assertLessThan(strpos($desfazer, 'pastas.push(p)'), strpos($desfazer, "if (!res.ok || !res.j.ok) throw new Error"));
        self::assertStringContainsString('Number(r.documentos) === saiu.arquivos.length', $desfazer);
        self::assertStringContainsString('Number(r.secoes) === saiu.pastas.length', $desfazer);
        self::assertStringContainsString('Number(res.j.paraARaiz || 0) === 0', $desfazer);
        self::assertStringContainsString("if (!bate) { recarregarDocumentos(", $desfazer);
        // Contagem do dc L4935: as CHAVES selecionadas, não a subárvore que voltou junto.
        self::assertStringContainsString('const desfazer = desfazerDe(res.j, saiu, itens.length);', $excluir);
        self::assertStringContainsString("if (aindaEMeu()) toast(nChaves > 1 ? BJ_T.t('pasta_scripts.js.explorador.restaurados_varios', { n: nChaves }) : BJ_T.t('pasta_scripts.js.explorador.restaurado'));", $desfazer);
        self::assertTextoDoScript('{n} itens restaurados', 'explorador.restaurados_varios', $desfazer); // textos do desenho (dc L4935)
        self::assertTextoDoScript('Restaurado', 'explorador.restaurado', $desfazer);
        self::assertStringNotContainsString('saiu.arquivos.length + saiu.pastas.length', $desfazer);
        // Corrida: a resposta de um Desfazer em voo não atropela o toast de uma exclusão mais nova.
        self::assertStringContainsString('const meuToast = toastSeq;', $desfazer);
        self::assertStringContainsString('const aindaEMeu = function () { return toastSeq === meuToast; };', $desfazer);
        self::assertStringContainsString('toastSeq++;', $this->funcao('toast'));
    }

    #[TestDox('toast: Desfazer só com ação, some com o toast, um clique só; os mesmos 4,2 s do dc (L4772)')]
    public function testToastComDesfazer(): void
    {
        $js    = $this->js();
        $toast = $this->funcao('toast');

        self::assertStringNotContainsString('TOAST_DESFAZER_MS', $js, 'o desenho manda: Desfazer não alonga o toast');
        self::assertStringContainsString('const TOAST_MS       = 4200;', $js);
        self::assertStringContainsString("toastDesfazerAcao = !erro && typeof desfazer === 'function' && el.toastDesfazer ? desfazer : null;", $toast);
        self::assertStringContainsString('el.toastDesfazer.hidden = !toastDesfazerAcao;', $toast);
        self::assertStringContainsString('toastTimer = setTimeout(esconderToast, erro ? 6000 : TOAST_MS);', $toast);
        self::assertStringContainsString("if (!acao || el.toastDesfazer.disabled) return;", $js);
        self::assertStringContainsString('el.toastDesfazer.disabled = true;', $js);
        // Estilo do desenho (dc L2277): #7cc4ff, 13px/700, sem fundo nem borda.
        $css = (string) file_get_contents(self::CSS);
        self::assertStringContainsString('--pex-toast-desfazer: #7cc4ff;', $css);
        self::assertMatchesRegularExpression('/\.pex-toast-desfazer \{[^}]*background: transparent;[^}]*color: var\(--pex-toast-desfazer\);[^}]*font-size: 13px;[^}]*font-weight: 700;/', $css);
    }

    #[TestDox('lixeira: modal montado por createElement/textContent (zero innerHTML) e pendurado no <body>; nota sem prazo de purga (D-DOC-RET); Restaurar com o csrfLote')]
    public function testModalDaLixeira(): void
    {
        $js    = $this->js();
        $modal = $this->funcaoLix('montarModalDaLixeira');

        foreach ([$js, $this->lix()] as $fonte) {
            self::assertStringNotContainsString('innerHTML', $fonte);
            self::assertStringNotContainsString('insertAdjacentHTML', $fonte);
        }
        self::assertStringContainsString('document.body.appendChild(raizModal);', $modal, 'fora do painel animado da aba');
        self::assertStringContainsString("class: 'modal fade pex-modal pex-lix'", $modal);
        // Sem purga automática (D-DOC-RET, 07/10): a tela não pode prometer prazo nem apagamento de vez.
        self::assertTextoDoScript('Itens na lixeira podem ser restaurados por 30 dias. Depois disso, são apagados de vez automaticamente.', 'explorador.lix_nota', $modal);
        self::assertStringNotContainsString('DIAS_NA_LIXEIRA', $js);
        self::assertDoesNotMatchRegularExpression('/\\d+ dias na lixeira|apagados de vez\\./', $js);
        // A linha: nome por `text` (textContent), excluído em/por e caminho original.
        $linha = $this->funcaoLix('linhaDaLixeira');
        self::assertStringContainsString("h('span', { class: 'pex-lix-nome', id: nomeId, text: it.nome, title: it.nome })", $linha);
        self::assertStringContainsString("const excluido = dataExclusao ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_em', { data: dataExclusao }) : BJ_T.t('pasta_scripts.js.explorador.lix_excluido_sem_data');", $linha);
        self::assertStringContainsString("const quando = it.excluidoPor && it.excluidoPor.nome ? BJ_T.t('pasta_scripts.js.explorador.lix_excluido_por', { quando: excluido, nome: it.excluidoPor.nome }) : excluido;", $linha);
        self::assertStringContainsString("BJ_T.t('pasta_scripts.js.explorador.lix_local_original', { local: it.caminho || BJ_T.t('pasta_scripts.js.explorador.raiz') })", $linha);
        self::assertTextoDoScript('Excluído em {data}', 'explorador.lix_excluido_em', $linha);
        self::assertTextoDoScript('Excluído (data sem registro)', 'explorador.lix_excluido_sem_data', $linha);
        self::assertTextoDoScript('{quando} por {nome}', 'explorador.lix_excluido_por', $linha);
        self::assertTextoDoScript('Local original: {local}', 'explorador.lix_local_original', $linha);
        self::assertTextoDoScript('Raiz', 'explorador.raiz', $linha);
        // Restaurar (um ou selecionados) pelo MESMO token do lote; a lista é relida depois.
        $restaurar = $this->funcaoLix('restaurarDaLixeira');
        self::assertStringContainsString('postJson(cfg.urlRestaurar, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes })', $restaurar);
        self::assertStringContainsString('lixeiraRestaurou = true;', $restaurar);
        self::assertStringContainsString('return lerLixeira()', $restaurar);
        self::assertStringContainsString("fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })", $this->funcaoLix('ler'));
        self::assertStringContainsString('return ler(cfg.urlLixeira)', $this->funcaoLix('lerLixeira'));
        // Fechar depois de restaurar recarrega a lista (a lixeira não traz URL nem token dos itens).
        self::assertStringContainsString('if (typeof cfg.aoFechar === \'function\') cfg.aoFechar(restaurou);', $modal);
        self::assertStringContainsString("if (restaurou) { recarregarDocumentos(", $this->funcao('aoFecharLixeira'));
        // Foco: volta a quem abriu (Organizar → o botão; menu de contexto → a lista).
        self::assertStringContainsString('lixeiraFocoDeVolta = el.menuOrganizar && el.menuOrganizar.contains(ativo) ? el.btnOrganizar', $this->funcao('abrirLixeira'));
        self::assertStringContainsString("if (volta && volta.isConnected && typeof volta.focus === 'function') volta.focus({ preventScroll: true });", $this->funcao('aoFecharLixeira'));
        // A aba passa ao modal as rotas e o token do #pexDados.
        $abrir = $this->funcao('abrirLixeira');
        self::assertStringContainsString('window.BJ_LixeiraDaPasta.abrir({', $abrir);
        foreach (['urlLixeira:      cfg.urlLixeira,', 'urlRestaurar:    cfg.urlRestaurar,', 'urlExcluirDeVez: cfg.urlExcluirDeVez,', 'csrfLote:        cfg.csrfLote,', 'pastaExcluida:   cfg.pastaExcluida,'] as $par) {
            self::assertStringContainsString($par, $abrir);
        }
        // Acesso: menu do fundo e Organizar.
        self::assertStringContainsString("op(rotuloLixeira(), 'bi-trash3', abrirLixeira),", $this->funcao('opcoesDoMenu'));
        self::assertStringContainsString("el.btnLixeira.addEventListener('click', abrirLixeira);", $js);
    }

    #[TestDox('lixeira: o excluído dentro de pasta também na lixeira fica agrupado embaixo dela (paiNaLixeira/secaoNaLixeira), com guarda de ciclo')]
    public function testAgrupamento(): void
    {
        $arvore = $this->funcaoLix('arvoreDaLixeira');
        self::assertStringContainsString("? (it.paiNaLixeira && it.paiId != null ? Number(it.paiId) : null)", $arvore);
        self::assertStringContainsString(": (it.secaoNaLixeira && it.secaoId != null ? Number(it.secaoId) : null);", $arvore);
        self::assertStringContainsString('if (pai != null && pastasNaLista[pai]', $arvore, 'só agrupa quando o pai ESTÁ na lista');

        $render = $this->funcaoLix('renderizarLixeira');
        self::assertStringContainsString('if (vistos[chave] || nivel > 50) return;', $render);
        self::assertStringContainsString('montar(f, nivel + 1, it)', $render);
        self::assertStringContainsString("m.lista.textContent = '';", $render);

        $linha = $this->funcaoLix('linhaDaLixeira');
        self::assertStringContainsString("const junto = !!pai && pai.excluidoEm != null && pai.excluidoEm === it.excluidoEm;", $linha);
        self::assertTextoDoScript('Excluído junto com a pasta "{nome}"', 'explorador.lix_junto_com', $linha);
    }

    #[TestDox('lápide da Pasta inteira: sem caixa de marcar, sem Restaurar e sem Desfazer')]
    public function testPastaExcluidaSemEscrita(): void
    {
        self::assertStringContainsString('function podeEscreverNaLixeira() { return !cfg.pastaExcluida && !!cfg.urlRestaurar && !!cfg.csrfLote; }', $this->lix());
        $linha = $this->funcaoLix('linhaDaLixeira');
        self::assertStringContainsString("escrever ? h('input', { type: 'checkbox'", $linha);
        self::assertStringContainsString("escrever ? h('button', {", $linha);
        self::assertStringContainsString('if (!chaves.length || lixeiraOcupada || !podeEscreverNaLixeira()) return;', $this->funcaoLix('restaurarDaLixeira'));
        self::assertStringContainsString("hidden: !podeEscreverNaLixeira()", $this->funcaoLix('montarModalDaLixeira'));
        self::assertStringContainsString('m.restaurarSel.hidden = !podeEscreverNaLixeira();', $this->funcaoLix('abrir'), 'a cada abertura (o modal serve a várias pastas no Expediente)');
        self::assertStringContainsString('m.somenteVer.hidden = !cfg.pastaExcluida;', $this->funcaoLix('abrir'));
        self::assertStringContainsString('cfg.pastaExcluida ||', $this->funcao('desfazerDe'));
    }

    #[TestDox('excluir de vez: botão por item e no rodapé, confirmação ANTES do pedido, mesmo token do lote, relê a lista; sem permissão, sem botão')]
    public function testExcluirDeVez(): void
    {
        $js = $this->js();
        self::assertStringContainsString("urlExcluirDeVez:     dados.urlExcluirDeVez || '',", $js);
        self::assertStringContainsString('function podeExcluirDeVez() { return podeEscreverNaLixeira() && !!cfg.urlExcluirDeVez; }', $this->lix());

        // Linha: o botão só nasce quando pode escrever na lixeira (lápide e leitor ficam sem ele).
        $linha = $this->funcaoLix('linhaDaLixeira');
        self::assertStringContainsString("escrever && podeExcluirDeVez() ? h('button', {", $linha);
        self::assertStringContainsString("'data-pex-lix-excluir-de-vez': chave,", $linha);
        self::assertStringContainsString("icone('bi-x-octagon')", $linha);
        self::assertTextoDoScript('Excluir de vez', 'explorador.lix_excluir_de_vez', $linha);

        // Rodapé: escondido sem permissão; o rótulo conta os selecionados.
        $modal = $this->funcaoLix('montarModalDaLixeira');
        self::assertStringContainsString("id: 'pexLixeiraExcluirDeVezSel', disabled: true, hidden: !podeExcluirDeVez() }", $modal);
        self::assertStringContainsString("h('div', { class: 'modal-footer' }, [fechar, excluirSel, restaurarSel]),", $modal);
        self::assertStringContainsString('excluirDeVezDaLixeira(Array.from(lixeiraMarcados));', $modal);
        self::assertStringContainsString('excluirDeVezDaLixeira([x.dataset.pexLixExcluirDeVez]);', $modal);
        self::assertTextoDoScript('Excluir de vez os selecionados', 'explorador.lix_excluir_de_vez_sel', $modal);
        $botoes = $this->funcaoLix('atualizarBotaoRestaurarSelecionados');
        self::assertStringContainsString('x.disabled = lixeiraOcupada || n === 0 || !podeExcluirDeVez();', $botoes);
        self::assertTextoDoScript('Excluir de vez os selecionados ({n})', 'explorador.lix_excluir_de_vez_sel_n', $botoes);

        // A ação: guarda de permissão, confirmação ANTES do pedido, mesmo token, relê a lista.
        $excluir = $this->funcaoLix('excluirDeVezDaLixeira');
        self::assertStringContainsString('if (!chaves.length || lixeiraOcupada || !podeExcluirDeVez()) return;', $excluir);
        self::assertStringContainsString('window.confirmarUIPs ? window.confirmarUIPs(pergunta, true) : Promise.resolve(confirm(pergunta))', $excluir);
        self::assertStringContainsString('if (sim) excluirDeVezConfirmado();', $excluir);
        self::assertLessThan(strpos($excluir, 'postJson('), strpos($excluir, 'window.confirmarUIPs(pergunta, true)'));
        self::assertLessThan(strpos($excluir, 'postJson('), strpos($excluir, 'function excluirDeVezConfirmado() {'));
        self::assertStringContainsString('postJson(cfg.urlExcluirDeVez, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes })', $excluir);
        self::assertStringContainsString('return lerLixeira()', $excluir);
        self::assertTextoDoScript('Excluir de vez {n} item(ns)? Eles saem do sistema e não podem ser restaurados.', 'explorador.lix_confirmar_excluir_de_vez', $excluir);
        self::assertTextoDoScript('1 item excluído de vez', 'explorador.lix_excluido_de_vez_um', $excluir);
        self::assertTextoDoScript('{n} itens excluídos de vez', 'explorador.lix_excluidos_de_vez_varios', $excluir);
        self::assertTextoDoScript('Não foi possível excluir de vez.', 'explorador.lix_erro_excluir_de_vez', $excluir);

        // Cores do "Excluir definitivamente" da lixeira do chat (desenho 06, :596).
        $css = (string) file_get_contents(self::CSS);
        self::assertMatchesRegularExpression('/\.pex-lix-excluir-de-vez \{[^}]*border: 1px solid #f0c9cd;[^}]*background: #fff;[^}]*color: #a3232b;/', $css);
        self::assertMatchesRegularExpression('/\.pex-lix-excluir-de-vez:hover,\s*\.pex-lix-excluir-de-vez:focus-visible \{[^}]*background: #fdecee;/', $css);
    }

    #[TestDox('abrir da lixeira: o arquivo (não a pasta) abre numa aba nova pela rota urlLixeira/<id>, pelo nome e pelo botão Abrir, sem restaurar')]
    public function testAbrirArquivoDaLixeira(): void
    {
        self::assertStringContainsString("function urlParaAbrir(it) { return String(cfg.urlLixeira).replace(/\\/+$/, '') + '/' + Number(it.id); }", $this->lix());
        $linha = $this->funcaoLix('linhaDaLixeira');
        self::assertStringContainsString("h('a', { class: 'pex-lix-nome pex-lix-nome--link', id: nomeId, href: urlParaAbrir(it), target: '_blank', rel: 'noopener', text: it.nome,", $linha);
        self::assertStringContainsString("ehPasta ? null : h('a', {", $linha, 'pasta não abre');
        self::assertStringContainsString("class: 'btn btn-sm btn-outline-secondary pex-lix-abrir',", $linha);
        self::assertTextoDoScript('Abrir', 'explorador.lix_abrir', $linha);
        self::assertTextoDoScript('Abrir "{nome}" numa aba nova, só para ver (continua na lixeira)', 'explorador.lix_abrir_titulo', $linha);
    }

    #[TestDox('o modal da lixeira não pinta a tela: o .pex-lix (raiz do .modal, tela inteira) recebe só os tokens, sem o fundo branco do cartão')]
    public function testModalNaoEscondeAPaginaAtras(): void
    {
        $css = (string) file_get_contents(self::CSS);
        self::assertSame(1, preg_match('/^\.pex-lix,\s*\.pex \{([^}]*)\}/m', $css, $m), 'o bloco de tokens do .pex-lix');
        foreach (['background', 'border:', 'box-shadow', 'border-radius'] as $prop) {
            self::assertStringNotContainsString($prop, preg_replace('/--[\w-]+:[^;]*;/', '', $m[1]) ?? '', "o .pex-lix não leva {$prop} do cartão");
        }
        self::assertMatchesRegularExpression('/^\.pex \{[^}]*background: var\(--pex-card-bg\);/m', $css, 'o cartão continua no .pex');
    }
}
