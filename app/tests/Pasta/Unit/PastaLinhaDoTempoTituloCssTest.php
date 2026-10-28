<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Título "Linha do tempo da pasta" invisível, aparece no hover (desenho 02 VS 28.10.26, .bjLinhaT). */
final class PastaLinhaDoTempoTituloCssTest extends TestCase
{
    #[TestDox('O título nasce com opacidade 0 e aparece com o mouse ou o foco na faixa')]
    public function testTituloSoNoHover(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/pasta-andamento.css');

        self::assertMatchesRegularExpression('/\.ps-and-rotulo \{[^}]*opacity: 0;[^}]*transition: opacity \.25s;/', $css);
        self::assertStringContainsString('.bjLinhaT:hover .bjLinhaTit,', $css);
        self::assertMatchesRegularExpression('/\.bjLinhaT:focus-within \.bjLinhaTit \{ opacity: 1; \}/', $css);
    }

    #[TestDox('O template marca a faixa (.bjLinhaT) e o título (.bjLinhaTit)')]
    public function testTemplateMarcado(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/pasta/_andamento.html.twig');

        self::assertStringContainsString('class="ps-and bjLinhaT"', $twig);
        self::assertStringContainsString('class="ps-and-rotulo bjLinhaTit"', $twig);
    }
}
