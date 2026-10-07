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
        self::assertStringContainsString('aria-controls="psNotaMenu{{ nota.id }}"', $item);
        self::assertStringContainsString('id="psNotaMenu{{ nota.id }}"', $item);
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
}
