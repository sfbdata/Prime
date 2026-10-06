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
 */
final class PastaExploradorLixeiraTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-explorador.css';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
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
        self::assertStringContainsString('const desfazer = desfazerDe(res.j, saiu);', $excluir);
        // confirm() mantido (S-3), ANTES do pedido.
        self::assertLessThan(strpos($excluir, 'postJson('), strpos($excluir, 'if (!confirm(avisoExclusaoDoLote(itens))) return;'));
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
        self::assertStringContainsString("toast(n > 1 ? n + ' itens restaurados' : 'Restaurado');", $desfazer, 'textos do desenho (dc L4935)');
    }

    #[TestDox('toast: Desfazer só com ação, some com o toast, um clique só; fica 8 s (o resto continua 4,2 s do dc)')]
    public function testToastComDesfazer(): void
    {
        $js    = $this->js();
        $toast = $this->funcao('toast');

        self::assertStringContainsString('const TOAST_DESFAZER_MS = 8000;', $js);
        self::assertStringContainsString('const TOAST_MS       = 4200;', $js);
        self::assertStringContainsString("toastDesfazerAcao = !erro && typeof desfazer === 'function' && el.toastDesfazer ? desfazer : null;", $toast);
        self::assertStringContainsString('el.toastDesfazer.hidden = !toastDesfazerAcao;', $toast);
        self::assertStringContainsString('(toastDesfazerAcao ? TOAST_DESFAZER_MS : TOAST_MS)', $toast);
        self::assertStringContainsString("if (!acao || el.toastDesfazer.disabled) return;", $js);
        self::assertStringContainsString('el.toastDesfazer.disabled = true;', $js);
        // Estilo do desenho (dc L2277): #7cc4ff, 13px/700, sem fundo nem borda.
        $css = (string) file_get_contents(self::CSS);
        self::assertStringContainsString('--pex-toast-desfazer: #7cc4ff;', $css);
        self::assertMatchesRegularExpression('/\.pex-toast-desfazer \{[^}]*background: transparent;[^}]*color: var\(--pex-toast-desfazer\);[^}]*font-size: 13px;[^}]*font-weight: 700;/', $css);
    }

    #[TestDox('lixeira: modal montado por createElement/textContent (zero innerHTML) e pendurado no <body>; nota dos 30 dias; Restaurar com o csrfLote')]
    public function testModalDaLixeira(): void
    {
        $js    = $this->js();
        $modal = $this->funcao('montarModalDaLixeira');

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringContainsString('document.body.appendChild(raizModal);', $modal, 'fora do painel animado da aba');
        self::assertStringContainsString("class: 'modal fade pex-modal pex-lix'", $modal);
        self::assertStringContainsString("'Itens ficam ' + DIAS_NA_LIXEIRA + ' dias na lixeira. Depois disso são apagados de vez.'", $modal);
        self::assertStringContainsString('const DIAS_NA_LIXEIRA   = 30;', $js);
        // A linha: nome por `text` (textContent), excluído em/por e caminho original.
        $linha = $this->funcao('linhaDaLixeira');
        self::assertStringContainsString("h('span', { class: 'pex-lix-nome', id: nomeId, text: it.nome, title: it.nome })", $linha);
        self::assertStringContainsString("'Excluído em ' + (formatarDataHora(it.excluidoEm) || '—') + (it.excluidoPor && it.excluidoPor.nome ? ' por ' + it.excluidoPor.nome : '')", $linha);
        self::assertStringContainsString("'Local original: ' + (it.caminho || 'Raiz')", $linha);
        // Restaurar (um ou selecionados) pelo MESMO token do lote; a lista é relida depois.
        $restaurar = $this->funcao('restaurarDaLixeira');
        self::assertStringContainsString('postJson(cfg.urlRestaurar, { _token: cfg.csrfLote, documentos: lote.documentos, secoes: lote.secoes })', $restaurar);
        self::assertStringContainsString('lixeiraRestaurou = true;', $restaurar);
        self::assertStringContainsString('return lerLixeira()', $restaurar);
        self::assertStringContainsString("fetch(cfg.urlLixeira, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })", $this->funcao('lerLixeira'));
        // Fechar depois de restaurar recarrega a lista (a lixeira não traz URL nem token dos itens).
        self::assertStringContainsString("if (lixeiraRestaurou) { lixeiraRestaurou = false; recarregarDocumentos(", $modal);
        // Acesso: menu do fundo e Organizar.
        self::assertStringContainsString("op(rotuloLixeira(), 'bi-trash3', abrirLixeira),", $this->funcao('opcoesDoMenu'));
        self::assertStringContainsString("el.btnLixeira.addEventListener('click', abrirLixeira);", $js);
    }

    #[TestDox('lixeira: o excluído dentro de pasta também na lixeira fica agrupado embaixo dela (paiNaLixeira/secaoNaLixeira), com guarda de ciclo')]
    public function testAgrupamento(): void
    {
        $arvore = $this->funcao('arvoreDaLixeira');
        self::assertStringContainsString("? (it.paiNaLixeira && it.paiId != null ? Number(it.paiId) : null)", $arvore);
        self::assertStringContainsString(": (it.secaoNaLixeira && it.secaoId != null ? Number(it.secaoId) : null);", $arvore);
        self::assertStringContainsString('if (pai != null && pastasNaLista[pai]', $arvore, 'só agrupa quando o pai ESTÁ na lista');

        $render = $this->funcao('renderizarLixeira');
        self::assertStringContainsString('if (vistos[chave] || nivel > 50) return;', $render);
        self::assertStringContainsString('montar(f, nivel + 1, it)', $render);
        self::assertStringContainsString("m.lista.textContent = '';", $render);

        $linha = $this->funcao('linhaDaLixeira');
        self::assertStringContainsString("const junto = !!pai && pai.excluidoEm != null && pai.excluidoEm === it.excluidoEm;", $linha);
        self::assertStringContainsString("'Excluído junto com a pasta \"' + pai.nome + '\"'", $linha);
    }

    #[TestDox('lápide da Pasta inteira: sem caixa de marcar, sem Restaurar e sem Desfazer')]
    public function testPastaExcluidaSemEscrita(): void
    {
        self::assertStringContainsString('function podeEscreverNaLixeira() { return !cfg.pastaExcluida && !!cfg.urlRestaurar && !!cfg.csrfLote; }', $this->js());
        $linha = $this->funcao('linhaDaLixeira');
        self::assertStringContainsString("escrever ? h('input', { type: 'checkbox'", $linha);
        self::assertStringContainsString("escrever ? h('button', {", $linha);
        self::assertStringContainsString('if (!chaves.length || lixeiraOcupada || !podeEscreverNaLixeira()) return;', $this->funcao('restaurarDaLixeira'));
        self::assertStringContainsString("hidden: !podeEscreverNaLixeira()", $this->funcao('montarModalDaLixeira'));
        self::assertStringContainsString('cfg.pastaExcluida ||', $this->funcao('desfazerDe'));
    }
}
