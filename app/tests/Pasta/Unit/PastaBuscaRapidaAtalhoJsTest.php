<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guardas do atalho Ctrl + K do `pasta-busca-rapida.js`. Não há harness de navegador na suíte
 * (precedente: PastaExploradorContratoJsTest): trava-se o TEXTO das guardas.
 */
final class PastaBuscaRapidaAtalhoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-busca-rapida.js';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('o atalho é só Ctrl + K minúsculo, sem Shift nem Alt (Ctrl + Shift + K fica com o navegador)')]
    public function testSoCtrlKMinusculoSemShift(): void
    {
        $js = $this->js();

        self::assertStringContainsString("(e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key === 'k'", $js);
        self::assertStringNotContainsString("e.key === 'K'", $js);
    }

    #[TestDox('tecla em composição (acento, IME) não aciona nada antes de qualquer outra guarda')]
    public function testIgnoraComposicao(): void
    {
        $js = $this->js();
        $composicao = strpos($js, 'if (e.isComposing || e.keyCode === 229) { return; }');
        $atalho = strpos($js, "e.key === 'k'");

        self::assertNotFalse($composicao);
        self::assertNotFalse($atalho);
        self::assertLessThan($atalho, $composicao, 'a guarda de composição vem antes do atalho');
    }
}
