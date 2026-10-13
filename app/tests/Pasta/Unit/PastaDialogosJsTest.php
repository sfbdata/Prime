<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Teste de FOLHA do `public/js/pasta-dialogos.js` (lote L6): lê o JS, sem navegador. Trava o
 * que o desenho pede e o que a tela não pode perder; o comportamento na tela é do smoke.
 */
final class PastaDialogosJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-dialogos.js';

    private function js(): string
    {
        $js = file_get_contents(self::JS);
        self::assertIsString($js);

        return $js;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);
        self::assertNotFalse($fim);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('PMC-039: o contador usa o maxlength do campo e reconta ao abrir e fechar o modal')]
    public function testContador(): void
    {
        $f = $this->funcao('contadores');
        self::assertStringContainsString("campo.getAttribute('maxlength')", $f);
        self::assertStringContainsString("String(campo.value.length) + (limite ? '/' + limite : '')", $f);
        self::assertStringContainsString("modalMeta.addEventListener('show.bs.modal', atualizar)", $f);
        self::assertStringContainsString("modalMeta.addEventListener('hidden.bs.modal', atualizar)", $f);
    }

    #[TestDox('PMC-041: os chips saem do <select> (a fonte) e marcar/desmarcar mexe só na <option>')]
    public function testSelectEhAFonte(): void
    {
        $f = $this->funcao('seletorDeResponsaveis');
        self::assertStringContainsString('option.selected = !option.selected;', $f);
        self::assertStringContainsString("select.dispatchEvent(new Event('change', { bubbles: true }))", $f);
        self::assertStringContainsString("var on = !!(option && option.selected);", $f);
        self::assertStringContainsString("modalMeta.addEventListener('show.bs.modal', pintar)", $f, 'o modal pode abrir com marcação de outro script');
    }

    #[TestDox('PMC-041: Esc na lista fecha a lista e não o modal; Enter na busca marca o primeiro que sobrou')]
    public function testTeclasDaLista(): void
    {
        $f   = $this->funcao('seletorDeResponsaveis');
        $esc = substr($f, (int) strpos($f, "if (e.key === 'Escape')"), 160);
        self::assertStringContainsString('e.stopPropagation();', $esc);
        self::assertStringContainsString('fechar(true);', $esc);
        self::assertStringContainsString("e.key === 'Enter' && e.target === busca", $f);
    }

    #[TestDox('nada de HTML montado com texto: só textContent e nós já desenhados pelo Twig')]
    public function testSemInnerHtml(): void
    {
        self::assertStringNotContainsString('innerHTML', $this->js());
        self::assertStringNotContainsString('insertAdjacentHTML', $this->js());
    }

    #[TestDox('PMC-040: escolher grava o ISO no input de sempre e dispara change; dia passado fica indisponível')]
    public function testCalendarioGravaNoInput(): void
    {
        $f = $this->funcao('calendarioDoPrazo');
        self::assertStringContainsString("input.value = iso;\n            input.dispatchEvent(new Event('change', { bubbles: true }));", $f);
        self::assertStringContainsString('b.disabled = passado;', $f);
        self::assertStringContainsString("(passado ? ', indisponível' : '')", $f);
        self::assertStringContainsString("escolher(isoDe(hoje()))", $f, 'Hoje');
        self::assertStringContainsString("escolher('')", $f, 'Limpar');
        self::assertStringContainsString("if (i === 35 && d.getMonth() !== base.getMonth()) { break; }", $f, 'a grade de 5 ou 6 semanas do desenho');
        self::assertStringContainsString("modalMeta.addEventListener('show.bs.modal', pintarCampo)", $f, 'o input pode chegar preenchido por outro script');
    }

    #[TestDox('PMC-040: Esc no calendário fecha o calendário e não o modal')]
    public function testEscDoCalendario(): void
    {
        $f   = $this->funcao('calendarioDoPrazo');
        $esc = substr($f, (int) strpos($f, "cal.addEventListener('keydown'"), 220);
        self::assertStringContainsString('e.stopPropagation();', $esc);
        self::assertStringContainsString('fechar(true);', $esc);
    }

    #[TestDox('PAS-034: confirmarPs resolve true só no OK; Enter confirma, Esc cancela sem fechar o que está atrás')]
    public function testConfirmarPs(): void
    {
        $f = $this->funcao('ligarConfirmacao');
        self::assertStringContainsString("e.target.closest('[data-ps-cf-ok]')) { encerrarConfirmacao(true); }", $f);
        self::assertStringContainsString("e.target === fundo || e.target.closest('[data-ps-cf-cancelar]')) { encerrarConfirmacao(false); return; }", $f);
        $esc = substr($f, (int) strpos($f, "if (e.key === 'Escape')"), 200);
        self::assertStringContainsString('e.stopPropagation();', $esc);
        self::assertStringContainsString('encerrarConfirmacao(false);', $esc);
        self::assertStringContainsString("e.key === 'Enter' && !e.target.closest('[data-ps-cf-cancelar]')", $f);

        $c = $this->funcao('confirmarPs');
        self::assertStringContainsString("Promise.resolve(window.confirm(", $c, 'sem a marcação, a pergunta continua sendo feita');
        self::assertStringContainsString("o.rotulo || 'Excluir'", $c);
        self::assertStringContainsString(".textContent = o.texto || ''", $c, 'o trecho entra como TEXTO');
    }

    #[TestDox('PAS-034: avisarPs mostra o aviso por 2,6 s (gmAviso do desenho) e cai no alert sem a marcação')]
    public function testAvisarPs(): void
    {
        $f = $this->funcao('avisarPs');
        self::assertStringContainsString('setTimeout(function () { aviso.hidden = true; }, 2600);', $f);
        self::assertStringContainsString("if (!aviso) { window.alert(mensagem); return; }", $f);
        self::assertStringContainsString("var texto = String(mensagem || '');", $f);
        self::assertStringContainsString("aviso.querySelector('span').textContent = texto;", $f);
        self::assertStringContainsString('window.confirmarPs = confirmarPs;', $this->js());
        self::assertStringContainsString('window.avisarPs = avisarPs;', $this->js());
    }

    #[TestDox('PAS-034c: o foco inicial da confirmação é o Cancelar, e Enter no Cancelar cancela')]
    public function testFocoInicialNoCancelar(): void
    {
        $c = $this->funcao('confirmarPs');
        self::assertStringContainsString("var cancelar = fundo.querySelector('.ps-cf-rodape [data-ps-cf-cancelar]') || caixa;", $c);
        self::assertStringContainsString('setTimeout(function () { cancelar.focus(); }, 30);', $c);
        self::assertStringNotContainsString('caixa.focus()', $c, 'com a caixa focada, um Enter perdido confirmava a exclusão');

        $f      = $this->funcao('ligarConfirmacao');
        $enter  = strpos($f, "e.key === 'Enter' && e.target.closest('[data-ps-cf-cancelar]')");
        self::assertNotFalse($enter, 'Enter no Cancelar tem ramo próprio');
        $ramo = substr($f, $enter, 260);
        self::assertStringContainsString('e.preventDefault();', $ramo);
        self::assertStringContainsString('encerrarConfirmacao(false);', $ramo);
        self::assertStringNotContainsString('encerrarConfirmacao(true)', substr($ramo, 0, (int) strpos($ramo, '} else if')), 'o ramo do Cancelar não confirma');
        // O ramo do Cancelar vem ANTES do "Enter confirma": a ordem do if/else decide.
        self::assertLessThan(
            strpos($f, "e.key === 'Enter' && !e.target.closest('[data-ps-cf-cancelar]')"),
            $enter,
        );
    }

    #[TestDox('PAS-034c: as regiões vivas do aviso nascem na carga; avisarPs não troca o role do #psAviso escondido')]
    public function testRegioesVivasCriadasNaCarga(): void
    {
        $r = $this->funcao('criarRegioesVivas');
        self::assertStringContainsString("document.createElement('div')", $r);
        self::assertStringContainsString("r.setAttribute('role', papel);", $r);
        self::assertStringContainsString("r.setAttribute('aria-live', vivo);", $r);
        self::assertStringContainsString("r.setAttribute('aria-atomic', 'true');", $r);
        self::assertStringContainsString("r.className = 'visually-hidden';", $r, 'escondida só visualmente, nunca com hidden');
        self::assertStringNotContainsString('r.hidden', $r);
        self::assertStringContainsString("document.body.appendChild(r);", $r);
        self::assertStringContainsString("return { status: regiao('status', 'polite'), alerta: regiao('alert', 'assertive') };", $r);
        self::assertStringContainsString("aviso.setAttribute('aria-hidden', 'true');", $r, 'o visual não é lido duas vezes');
        self::assertStringContainsString("aviso.removeAttribute('role');", $r);

        // Criadas na CARGA, no corpo da IIFE — não sob demanda dentro de avisarPs.
        self::assertStringContainsString("\n    var regioesVivas = criarRegioesVivas();\n", $this->js());

        $f = $this->funcao('avisarPs');
        self::assertStringNotContainsString("setAttribute('role'", $f, 'trocar o role do elemento escondido não é anunciado');
        self::assertStringContainsString('var alvo = erro ? regioesVivas.alerta : regioesVivas.status;', $f);
        self::assertStringContainsString("regioesVivas.status.textContent = '';", $f);
        self::assertStringContainsString("regioesVivas.alerta.textContent = '';", $f);
        self::assertStringContainsString('vivoTimer = setTimeout(function () { alvo.textContent = texto; }, 50);', $f);
    }
}
