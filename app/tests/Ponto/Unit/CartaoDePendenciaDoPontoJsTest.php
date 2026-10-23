<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O cartão diário de pendência de registro (spec `ponto-esquecimento-nao-trava.md` R9), lido no texto do script: a
 * guarda por dia, o adiar e o fechar no localStorage por pessoa e escritório, sempre em try/catch, e o DOM sem innerHTML.
 */
#[CoversNothing]
final class CartaoDePendenciaDoPontoJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-pendente.js');
    }

    #[TestDox('Guarda por dia, por pessoa e escritório: mostrar marca o dia; fechar esconde até amanhã; adiar, 10 min')]
    public function testGuardaPorDiaAdiarEFechar(): void
    {
        $js = self::js();
        self::assertStringContainsString("var CHAVE = 'bj-ponto-pendente:' + (raiz.getAttribute('data-eu') || '');", $js);
        self::assertStringContainsString('var ADIAR_MS = 10 * 60 * 1000;', $js);
        self::assertStringContainsString("if (guardado.adiarAte) { return agora >= guardado.adiarAte; }", $js);
        self::assertStringContainsString('return guardado.dia !== hoje();', $js);
        self::assertStringContainsString("fechar.addEventListener('click', function () { gravar({ dia: hoje() }); tirar(); });", $js);
        self::assertStringContainsString("adiar.addEventListener('click', function () { gravar({ dia: hoje(), adiarAte: Date.now() + ADIAR_MS }); tirar(); });", $js);
        self::assertStringContainsString("gravar({ dia: hoje() });   // mostrado", $js);
        self::assertStringContainsString("try { return JSON.parse(localStorage.getItem(CHAVE) || '{}') || {}; } catch (e) { return {}; }", $js);
        self::assertStringContainsString('try { localStorage.setItem(CHAVE, JSON.stringify(valor)); } catch (e)', $js);
    }

    #[TestDox('O componente do desenho: a pilha #bjl-box, o cartão com a cor e o ícone do "Regularizar ponto de ontem"; só textContent e link interno')]
    public function testComponenteSemInnerHtml(): void
    {
        $js = self::js();
        self::assertStringContainsString("var box = document.getElementById('bjl-box');", $js, 'a mesma pilha dos lembretes');
        self::assertStringContainsString("var COR = '#c76a12';", $js);
        self::assertStringContainsString("var ICONE = 'bi-exclamation-triangle';", $js);
        foreach (['bjl-cartao', 'bjl-icone', 'bjl-rotulo', 'bjl-abrir', 'bjl-adiar', 'bjl-x', 'bjl-barra'] as $classe) {
            self::assertStringContainsString("'" . $classe . "'", $js, $classe);
        }
        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringContainsString("c.link.charAt(0) === '/' && c.link.charAt(1) !== '/'", $js, 'só link interno');
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $js);
    }

    #[TestDox('Pilha compartilhada com a Agenda: cada cartão marca o dono; o desenhar da Agenda só remove os cartões dela e só tira #bjl-box sem filho de outro dono')]
    public function testPilhaCompartilhadaComAAgenda(): void
    {
        self::assertStringContainsString("cartao.setAttribute('data-bjl-dono', 'ponto');", self::js());

        $agenda = (string) file_get_contents(__DIR__ . '/../../../public/js/lembretes.js');
        $desenhar = substr($agenda, (int) strpos($agenda, 'function desenhar() {'), 1200);
        self::assertStringNotContainsString('[...box.children].forEach(c => { if (!ids.includes(c.id)) { c.remove(); } });', $desenhar, 'não remove cartão de outro dono');
        self::assertStringNotContainsString('if (box) { box.remove(); }', $desenhar, 'não remove a pilha com cartão de outro dono');
        self::assertStringContainsString("box.querySelectorAll('[data-bjl-dono=\"agenda\"]')", $desenhar);
        self::assertStringContainsString('if (!box.children.length) { box.remove(); }', $desenhar);
        self::assertStringContainsString('data-bjl-dono="agenda"', $agenda, 'o cartão da Agenda marca o dono');
    }
}
