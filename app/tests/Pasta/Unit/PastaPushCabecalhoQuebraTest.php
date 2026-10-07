<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * FPN-031: a faixa do cabeçalho do Push quebra linha (dc L.2412 `flex-wrap:wrap`). Se a faixa
 * cabe em 375px só o smoke diz; aqui se prova que as regras existem na folha, no lugar certo
 * (dentro do `max-width: 767.98px` as dos filtros e da IA). Os seletores são de FILHO DIRETO
 * da faixa; que o botão da IA e os filtros SÃO filhos diretos dela o
 * `Inteligencia\Functional\PastaPushAbaIaTelaTest` já trava (`.ps-card-cab > .ps-ia-gerar + .ps-push-filtros`).
 */
#[Group('pasta')]
final class PastaPushCabecalhoQuebraTest extends TestCase
{
    private const CSS =__DIR__ . '/../../../public/css/pasta-show.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    #[TestDox('a faixa do Push quebra linha em qualquer largura, com 8px entre as linhas')]
    public function testFaixaQuebraLinha(): void
    {
        self::assertMatchesRegularExpression(
            '/^\.ps-push > \.ps-card-cab--faixa \{[^}]*flex-wrap: wrap;[^}]*row-gap: 8px;/m',
            $this->css(),
        );
    }

    #[TestDox('abaixo de 768px os filtros ocupam a linha toda e o motivo da IA quebra dentro do botão')]
    public function testCelular(): void
    {
        $css    = $this->css();
        $inicio = strpos($css, ".ps-push > .ps-card-cab--faixa > .ps-push-filtros { flex-basis: 100%; }");
        self::assertNotFalse($inicio, 'regra dos filtros existe');

        // A regra mora DENTRO do bloco @media de 767.98px imediatamente anterior.
        $media = strrpos(substr($css, 0, $inicio), '@media');
        self::assertNotFalse($media);
        self::assertStringStartsWith('@media (max-width: 767.98px)', substr($css, $media));
        self::assertStringNotContainsString('}', substr($css, (int) strpos($css, '{', $media) + 1, $inicio - strpos($css, '{', $media) - 1), 'nenhum bloco fechado entre o @media e a regra');

        self::assertStringContainsString('.ps-push > .ps-card-cab--faixa > .ps-ia-gerar { max-width: 100%; }', $css);
        self::assertMatchesRegularExpression('/\.ps-push > \.ps-card-cab--faixa > \.ps-ia-gerar--indisponivel \{[^}]*height: auto;/', $css);
    }
}
