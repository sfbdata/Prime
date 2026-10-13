<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Teste de FOLHA do nota-tecnica.js (lê o JS e os partials; sem navegador): trava o contrato
 * entre o script e o cartão da nota. O comportamento na tela é do smoke do dono.
 */
#[Group('processo')]
final class NotaTecnicaJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';
    private const JS = self::APP . '/public/js/nota-tecnica.js';
    private const ITEM = self::APP . '/templates/processo/_nota_tecnica_item.html.twig';

    private function ler(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo, "não leu {$caminho}");

        return $conteudo;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->ler(self::JS);
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('EDT-021: durante o envio quem apaga o "Salvar nota" é EditorRico.marcarEnvio — nunca `disabled` direto, que a próxima tecla desfaria')]
    public function testSalvarUsaMarcarEnvioDoEditor(): void
    {
        $marcar = $this->funcao('marcarEnvio');
        self::assertStringContainsString('EditorRico.marcarEnvio(textarea, enviando)', $marcar);

        $nova = $this->funcao('salvarNova');
        self::assertStringContainsString('marcarEnvio(textarea, botao, true)', $nova);
        self::assertStringContainsString('marcarEnvio(textarea, botao, false)', $nova);
        self::assertStringNotContainsString('botao.disabled = ', $nova);
        // Com o editor vazio o botão já está apagado: Ctrl+Enter também não envia.
        self::assertStringContainsString('botao.disabled) { return; }', $nova);

        $editar = $this->funcao('editar');
        self::assertStringContainsString("textarea.setAttribute('data-editor-rico-envio', salvar.id)", $editar);
        self::assertStringContainsString('marcarEnvio(textarea, salvar, true)', $editar);
        self::assertStringNotContainsString('salvar.disabled = ', $editar);
        // O contrato é declarado ANTES de montar o editor (a montagem já calcula o estado do botão).
        self::assertLessThan(
            strpos($editar, 'montar(textarea)'),
            strpos($editar, "setAttribute('data-editor-rico-envio'"),
        );
    }

    #[TestDox('PMC-017: o botão direito no cartão abre o MESMO menu do ⋮, no ponto do clique, poupando links, botões, edição e texto selecionado')]
    public function testBotaoDireitoAbreOMenuDaNota(): void
    {
        $js = $this->ler(self::JS);
        $ini = strpos($js, "document.addEventListener('contextmenu'");
        self::assertNotFalse($ini, 'sem ouvinte de contextmenu');
        $bloco = substr($js, $ini, (int) strpos($js, "\n    });", $ini) - $ini);

        self::assertStringContainsString("closest('.ps-nota')", $bloco);
        self::assertStringContainsString("querySelector('.js-nota-menu')", $bloco, 'o menu é o do ⋮ do próprio cartão');
        self::assertStringContainsString('e.preventDefault()', $bloco);
        self::assertStringContainsString('abrirMenu(gatilho, e.clientX, e.clientY)', $bloco);
        foreach (['a, button', 'textarea', '[contenteditable="true"]', '.ps-nota-edicao', '.ps-nota-menu'] as $poupado) {
            self::assertStringContainsString($poupado, $bloco, "o botão direito em {$poupado} segue com o menu do navegador");
        }
        self::assertStringContainsString('window.getSelection', $bloco);
    }

    #[TestDox('PMC-017: o ⋮ abre pelo aria-controls, fecha os outros popovers e marca aria-expanded')]
    public function testGatilhoAbreOMenuDoAriaControls(): void
    {
        $abrir = $this->funcao('abrirMenu');

        self::assertStringContainsString("getAttribute('aria-controls')", $abrir);
        self::assertStringContainsString("new CustomEvent('ps:fechar-popovers')", $abrir);
        self::assertStringContainsString("setAttribute('aria-expanded', 'true')", $abrir);
        self::assertStringContainsString(':not([disabled])', $abrir, 'o foco não cai num item bloqueado');

        $item = $this->ler(self::ITEM);
        // O sufixo (FPN-020) é vazio fora do Push pendurado: o id segue psNotaMenu<id>.
        self::assertStringContainsString('aria-controls="psNotaMenu{{ nota.id }}{{ idSufixo }}"', $item);
        self::assertStringContainsString('id="psNotaMenu{{ nota.id }}{{ idSufixo }}"', $item);
    }

    #[TestDox('PMC-017: "Copiar texto" copia o texto como se lê (menção "@Nome"), e Editar/Excluir fecham o menu antes de agir')]
    public function testCopiarEAcoesDoMenu(): void
    {
        $copiar = $this->funcao('copiar');
        self::assertStringContainsString(".ps-nota-texto .ql-editor", $copiar);
        self::assertStringContainsString('innerText', $copiar);
        self::assertStringContainsString('navigator.clipboard.writeText', $copiar);

        $js = $this->ler(self::JS);
        self::assertStringContainsString("if (btnEditar) { fecharMenu(false); editar(btnEditar); return; }", $js);
        self::assertStringContainsString("if (btnExcluir) { fecharMenu(false); excluir(btnExcluir); }", $js);
        self::assertStringContainsString("closest('.js-nota-copiar')", $js);
    }

    #[TestDox('PMC-057: "Responder com outra nota" fecha o menu e abre o compositor de nota NOVA do próprio bloco, como o abrirNova do desenho')]
    public function testResponderAbreOCompositorDoBloco(): void
    {
        $js  = $this->ler(self::JS);
        $ini = strpos($js, "var itemResponder = alvo.closest('.js-nota-responder');");
        self::assertNotFalse($ini, 'sem o ouvinte do Responder');
        $bloco = substr($js, $ini, (int) strpos($js, 'return;', $ini) - $ini);

        self::assertStringContainsString('fecharMenu(false)', $bloco);
        self::assertStringContainsString('blocoDe(itemResponder)', $bloco);
        self::assertStringContainsString('abrirCompositor(blocoResp)', $bloco);
        // O compositor é o de nota nova (foco no editor): nenhuma requisição sai daqui.
        self::assertStringNotContainsString('fetch(', $bloco);
        self::assertStringContainsString('focar(quill, textarea)', $this->funcao('abrirCompositor'));
    }

    #[TestDox('FPN-020: a mesma nota pode estar duas vezes na página — editar mexe no cartão do botão clicado, excluir tira todas as cópias')]
    public function testCopiasDaMesmaNotaNaPagina(): void
    {
        $editar = $this->funcao('editar');
        self::assertStringContainsString("botaoEditar.closest('.ps-nota')", $editar);
        self::assertStringNotContainsString("getElementById('nota-tecnica-'", $editar, 'o id acharia a cópia da outra aba');

        $excluir = $this->funcao('excluir');
        self::assertStringContainsString(".ps-nota[data-nota-id=\"' + botaoExcluir.getAttribute('data-nota-id') + '\"]", $excluir);
        self::assertStringContainsString('copia.remove()', $excluir);
        self::assertStringContainsString('atualizarContagem(blocoDaCopia)', $excluir);

        // Do lado do partial: o sufixo entra no id do cartão, no aria-controls e no id do menu.
        $item = $this->ler(self::ITEM);
        self::assertStringContainsString('id="nota-tecnica-{{ nota.id }}{{ idSufixo }}" data-nota-id="{{ nota.id }}"', $item);
        self::assertStringContainsString('aria-controls="psNotaMenu{{ nota.id }}{{ idSufixo }}"', $item);
        self::assertStringContainsString('id="psNotaMenu{{ nota.id }}{{ idSufixo }}"', $item);
    }

    #[TestDox('FPN-020c: depois de editar, todas as cópias com o mesmo data-nota-id recebem os data-* do Editar e a marca "editada"; as outras, o texto novo')]
    public function testEditarAtualizaTodasAsCopias(): void
    {
        $editar = $this->funcao('editar');
        $restaura = strpos($editar, 'restaurar(dados.conteudoHtml);');
        $copias   = strpos($editar, 'atualizarCopias(item, dados);');
        self::assertNotFalse($restaura);
        self::assertNotFalse($copias, 'o sucesso do editar propaga para as cópias');
        self::assertGreaterThan($restaura, $copias, 'a cópia editada sai da caixa antes da propagação');
        // A atualização dos data-* e da marca mora num lugar só (vale para a cópia editada também).
        self::assertStringNotContainsString("botaoEditar.setAttribute('data-conteudo'", $editar);
        self::assertStringNotContainsString('marcarEditada(item', $editar);

        $atualizar = $this->funcao('atualizarCopias');
        self::assertStringContainsString("var id = item.getAttribute('data-nota-id');", $atualizar);
        self::assertStringContainsString(".ps-nota[data-nota-id=\"' + id + '\"]", $atualizar, 'acha as cópias pelo número, não pelo id com sufixo');
        self::assertStringContainsString("copia.querySelector('.js-nota-editar')", $atualizar);
        self::assertStringContainsString("botao.setAttribute('data-conteudo', dados.conteudo || '');", $atualizar);
        self::assertStringContainsString("botao.setAttribute('data-conteudo-html', dados.conteudoHtml || '');", $atualizar);
        self::assertStringContainsString('marcarEditada(copia, dados.editadaEm);', $atualizar);
        $pula  = strpos($atualizar, 'if (copia === item) { return; }');
        $troca = strpos($atualizar, 'texto.replaceWith(textoDaNota(dados.conteudoHtml));');
        self::assertNotFalse($pula, 'a cópia editada já foi restaurada');
        self::assertNotFalse($troca, 'as outras cópias recebem o texto novo');
        self::assertGreaterThan($pula, $troca);
        self::assertStringContainsString("var texto = copia.querySelector('.ps-nota-texto');", $atualizar);
        self::assertStringContainsString('if (texto) {', $atualizar, 'cópia com a caixa de edição aberta fica com o que a pessoa escreve');

        // O texto montado é o mesmo do partial e do restaurar (uma função só).
        $texto = $this->funcao('textoDaNota');
        foreach (["div.className = 'ps-nota-texto';", "externo.className = 'editor-rico-conteudo';", "interno.className = 'ql-editor';"] as $trecho) {
            self::assertStringContainsString($trecho, $texto);
        }
        self::assertStringContainsString('caixa.replaceWith(textoDaNota(html));', $editar);
        self::assertStringContainsString('<div class="ps-nota-texto">' . "\n" . '        <span class="editor-rico-conteudo"><span class="ql-editor">', $this->ler(self::ITEM));
    }

    #[TestDox('C6-FPN-01: a nota recém-criada entra no FIM da lista (beforeend), como o A.push do desenho — a lista é cronológica')]
    public function testNotaNovaEntraNoFim(): void
    {
        $nova = $this->funcao('salvarNova');
        self::assertStringContainsString("lista.insertAdjacentHTML('beforeend', dados.html || '')", $nova);
        self::assertStringNotContainsString("'afterbegin'", $this->ler(self::JS));
    }

    #[TestDox('C6-FPN-04: abrir o compositor mostra o bloco escondido (hidden + display); fechar, salvar e excluir avisam ps:notas-mudou')]
    public function testCompositorMostraOBlocoEscondido(): void
    {
        $mostrar = $this->funcao('mostrarBloco');
        self::assertStringContainsString('bloco.hidden = false;', $mostrar);
        self::assertStringContainsString("bloco.style.display = '';", $mostrar, 'o display:flex do .ps-notas venceria o hidden');

        $abrir = $this->funcao('abrirCompositor');
        self::assertLessThan(strpos($abrir, 'editor.hidden = false;'), strpos($abrir, 'mostrarBloco(bloco);'));

        self::assertStringContainsString("new CustomEvent('ps:notas-mudou', { bubbles: true })", $this->funcao('avisarMudanca'));
        self::assertStringContainsString('avisarMudanca(bloco);', $this->funcao('fecharCompositor'), 'salvar fecha o compositor: o aviso sai por aqui também');
        self::assertStringContainsString('avisarMudanca(blocoDaCopia);', $this->funcao('excluir'));

        // Do lado do partial: o bloco pendurado nasce escondido só quando vazio, com a marca.
        $bloco = $this->ler(self::APP . '/templates/processo/_notas_tecnicas.html.twig');
        self::assertStringContainsString('{% set blocoOculto = ocultoSeVazio and notas is empty %}', $bloco);
        self::assertStringContainsString('data-oculto-se-vazio', $bloco);
        self::assertStringContainsString('hidden style="display: none"', $bloco);
    }

    #[TestDox('C6-PMC-05: "Editar · bloqueado"/"Excluir · bloqueado" clicados fecham o menu e mostram o aviso da tela (avisarPs), sem ação')]
    public function testItemBloqueadoMostraOAviso(): void
    {
        $js  = $this->ler(self::JS);
        $ini = strpos($js, "var itemBloqueado = alvo.closest('.js-nota-bloqueado');");
        self::assertNotFalse($ini, 'sem o ouvinte do item bloqueado');
        $bloco = substr($js, $ini, (int) strpos($js, 'return;', $ini) - $ini);

        self::assertStringContainsString('fecharMenu(true)', $bloco);
        self::assertStringContainsString("getAttribute('data-aviso')", $bloco);
        self::assertStringContainsString('window.avisarPs(motivo', $bloco);
        self::assertStringNotContainsString('fetch(', $bloco);

        $item = $this->ler(self::ITEM);
        self::assertStringContainsString('data-aviso="Só o autor edita a nota, em até 15 minutos"', $item);
        self::assertStringContainsString('data-aviso="Só o autor exclui a nota, em até 15 minutos"', $item);
        self::assertStringNotContainsString('disabled aria-disabled', $item, 'o `disabled` engolia o clique');
    }
}
