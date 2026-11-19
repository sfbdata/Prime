<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Dica dupla na lista do Expediente (lote 2 do Farlei, F5, 19/11): o texto cortado da coluna Ação mostrava a dica
 * preta do Bootstrap (o tooltip automático de células cortadas do base.html.twig) junto com a azul-clara do desenho
 * (data-tip, do expediente-lista.js). O automático agora ignora quem já tem dica própria, olhando o alvo e o elemento
 * sob o mouse. O comportamento na tela é conferido no navegador.
 */
final class TooltipAutomaticoSemDicaDuplaTest extends TestCase
{
    private static function base(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/base.html.twig');
    }

    private static function seletorDeDicaPropria(): string
    {
        self::assertSame(1, preg_match("/var DICA_PROPRIA = ((?:'[^']*'\\s*\\+?\\s*)+);/", self::base(), $m), 'o seletor das dicas próprias mora numa variável do tooltip automático');

        return implode('', array_map(static fn (string $p): string => trim($p, "' \n"), preg_split("/'\\s*\\+\\s*'/", $m[1]) ?: []));
    }

    /** @return iterable<string, array{string}> */
    public static function dicasDoDesenho(): iterable
    {
        yield 'Bootstrap (data-bs-toggle)' => ['[data-bs-toggle="tooltip"]'];
        yield 'Bootstrap já criado' => ['[data-bs-original-title]'];
        yield 'data-tip (Expediente, painel, assinatura)' => ['[data-tip]'];
        yield 'data-bj-tip (Central)' => ['[data-bj-tip]'];
        yield 'data-tip-proprio (Central)' => ['[data-tip-proprio]'];
        yield 'title na raiz da Central' => ['[data-central] [title]'];
        yield 'title na Central flutuante' => ['[data-central-flutuante] [title]'];
        yield 'title na janela da Central' => ['[data-flutuante-janela] [title]'];
    }

    #[TestDox('Célula cortada sem dica própria dentro da Central continua com o tooltip automático')]
    public function testCentralSoExcluiQuemTemTitle(): void
    {
        $seletor = self::seletorDeDicaPropria();

        foreach (['[data-central],', '[data-central-flutuante],', '[data-flutuante-janela],'] as $raizInteira) {
            self::assertStringNotContainsString($raizInteira, $seletor . ',');
        }
    }

    #[DataProvider('dicasDoDesenho')]
    #[TestDox('O tooltip automático ignora quem tem a dica do desenho $seletor')]
    public function testIgnoraDicaPropria(string $seletor): void
    {
        self::assertStringContainsString($seletor, self::seletorDeDicaPropria());
    }

    #[TestDox('A conferência vale para o alvo cortado e para o elemento sob o mouse, antes de criar o tooltip')]
    public function testConfereAlvoEElementoSobOMouse(): void
    {
        $base = self::base();
        $guarda = 'if (alvo.closest(DICA_PROPRIA) || ev.target.closest(DICA_PROPRIA)) return;';

        self::assertStringContainsString($guarda, $base);
        self::assertLessThan(strpos($base, 'new bootstrap.Tooltip(alvo'), strpos($base, $guarda));
    }
}
