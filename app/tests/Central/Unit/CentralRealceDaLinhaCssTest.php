<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guia visual v30 na Central (`docs/design/guia-visual-v30/`, frente A de 19/11/2026):
 * - realce da linha sob o cursor: fundo #2b7ab0, faixa interna de 4px #1f6f9c e texto branco, nas linhas de conversa,
 *   de contato e da equipe (a linha da própria pessoa não acende);
 * - pílula vira raio 4px (selo, dia, mensagem de sistema e contadores); o interruptor e os círculos ficam.
 *
 * Que a linha fica azul na tela é o smoke do dono; aqui se prova que a regra está no seletor.
 */
#[CoversNothing]
final class CentralRealceDaLinhaCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/central.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    /** Corpos de todas as regras cuja lista de seletores contém exatamente `$seletor`. */
    private function regras(string $seletor): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css(), $m, PREG_SET_ORDER);
        $corpos = [];
        foreach ($m as [, $seletores, $corpo]) {
            $lista = array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), explode(',', $seletores));
            if (in_array($seletor, $lista, true)) {
                $corpos[] = $corpo;
            }
        }
        self::assertNotEmpty($corpos, 'Falta a regra ' . $seletor);

        return implode("\n", $corpos);
    }

    /** @return iterable<string, array{string}> */
    public static function linhas(): iterable
    {
        yield 'conversa' => ['.bj-central-linha:hover'];
        yield 'contato' => ['.bj-central-linha--contato:hover'];
        yield 'equipe' => ['.bj-central-equipe-linha:not(.bj-central-equipe-linha--eu):hover'];
    }

    #[DataProvider('linhas')]
    #[TestDox('linha de $_dataName sob o cursor: fundo #2b7ab0 do guia v30')]
    public function testRealceDaLinha(string $seletor): void
    {
        self::assertMatchesRegularExpression('/background:\s*var\(--bjc-linha-hv\);/', $this->regras($seletor));
    }

    #[TestDox('o azul do realce é o #2b7ab0 e a faixa é #1f6f9c, no claro e no escuro')]
    public function testTokensDoRealce(): void
    {
        self::assertSame(2, preg_match_all('/--bjc-linha-hv:\s*#2b7ab0;/', $this->css()));
        self::assertSame(2, preg_match_all('/--bjc-linha-hv-barra:\s*#1f6f9c;/', $this->css()));
        self::assertStringContainsString('box-shadow: inset 4px 0 0 var(--bjc-linha-hv-barra);', $this->regras('.bj-central-linha:hover'));
    }

    #[TestDox('texto branco na linha realçada: nome, hora e prévia da conversa; nome e cargo da equipe')]
    public function testTextoBranco(): void
    {
        self::assertMatchesRegularExpression('/color:\s*#fff;/', $this->regras('.bj-central-linha:hover .bj-central-linha-textos *'));
        foreach (['nome', 'sub', 'conversar'] as $parte) {
            self::assertMatchesRegularExpression('/color:\s*#fff;/', $this->regras('.bj-central-equipe-linha:not(.bj-central-equipe-linha--eu):hover .bj-central-equipe-' . $parte));
        }
    }

    /** O selo de não lidas tem o fundo quase igual ao do realce: no hover ele vira branco com o número azul. */
    public function testSeloVisivelNoRealce(): void
    {
        $selo = $this->regras('.bj-central-linha:hover .bj-central-linha-textos .bj-central-selo');
        self::assertMatchesRegularExpression('/background:\s*#fff;/', $selo);
        self::assertMatchesRegularExpression('/color:\s*var\(--bjc-linha-hv\);/', $selo);
        self::assertMatchesRegularExpression('/background:\s*rgba\(255, 255, 255, \.7\);/', $this->regras('.bj-central-linha:focus-visible .bj-central-linha-textos .bj-central-selo--mudo'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function raios(): iterable
    {
        yield 'selo de não lidas' => ['.bj-central-selo', '4px'];
        yield 'separador do dia' => ['.bj-central-dia', '4px'];
        yield 'mensagem de sistema' => ['.bj-central-sistema', '4px'];
        yield 'contador da aba' => ['.bj-central-aba-contador', '4px'];
        yield 'contador da equipe' => ['.bj-central-equipe-contador', '4px'];
        yield 'bolinha de marcada como não lida (círculo, fica)' => ['.bj-central-selo--marca', '50%'];
    }

    #[DataProvider('raios')]
    #[TestDox('canto do guia v30: $seletor = $raio')]
    public function testCantos(string $seletor, string $raio): void
    {
        self::assertMatchesRegularExpression('/border-radius:\s*' . preg_quote($raio, '/') . ';/', $this->regras($seletor));
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*999px/', $this->regras($seletor));
    }
}
