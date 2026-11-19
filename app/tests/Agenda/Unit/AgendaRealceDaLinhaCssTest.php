<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guia visual v30 (frente A de 19/11/2026) em a Agenda 1A: linha sob o cursor em #2b7ab0 com faixa interna de 4px
 * #1f6f9c e texto branco; pílula vira raio 4px; círculos e interruptor ficam redondos.
 * Que a linha fica azul na tela é o smoke do dono; aqui se prova que a regra está no seletor.
 */
#[CoversNothing]
final class AgendaRealceDaLinhaCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/a1-agenda.css';

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
        yield 'lista' => ['.a1-lin:hover'];
        yield 'lista com teclado' => ['.a1-lin:focus-visible'];
        yield 'painel de hoje' => ['.a1-hoje-lin:hover'];
    }

    #[DataProvider('linhas')]
    #[TestDox('linha sob o cursor %s: fundo #2b7ab0 e faixa #1f6f9c')]
    public function testRealceDaLinha(string $seletor): void
    {
        $corpo = $this->regras($seletor);
        self::assertMatchesRegularExpression('/background:\s*#2b7ab0;/', $corpo);
        self::assertStringContainsString('box-shadow: inset 4px 0 0 #1f6f9c;', $corpo);
    }

    /** @return iterable<string, array{string}> */
    public static function textosBrancos(): iterable
    {
        yield 'hora da lista' => ['.a1-lin:hover .a1-lin-hora'];
        yield 'título da lista' => ['.a1-lin:hover .a1-lin-tit'];
        yield 'meta da lista' => ['.a1-lin:hover .a1-lin-meta'];
        yield 'hora de hoje' => ['.a1-hoje-lin:hover .a1-hoje-hora'];
        yield 'título de hoje' => ['.a1-hoje-lin:hover .a1-hoje-tit'];
        yield 'agora' => ['.a1-hoje-lin:hover .a1-agora-tag'];
    }

    #[DataProvider('textosBrancos')]
    #[TestDox('texto branco na linha realçada: $seletor')]
    public function testTextoBranco(string $seletor): void
    {
        self::assertMatchesRegularExpression('/color:\s*#fff/', $this->regras($seletor));
    }

    /** @return iterable<string, array{string, string}> */
    public static function raios(): iterable
    {
        yield 'status (era pílula)' => ['.a1-st', '4px'];
        yield 'busca (era pílula)' => ['.a1-busca input', '4px'];
        yield 'bolinha do dia de hoje (círculo, fica)' => ['.is-hoje > .a1-sem-num', '50%'];
        yield 'avatar (círculo, fica)' => ['.a1-av', '50%'];
        yield 'ponto da lista (círculo, fica)' => ['.a1-lin-ponto', '50%'];
    }

    #[DataProvider('raios')]
    #[TestDox('canto do guia v30: $seletor = $raio')]
    public function testCantos(string $seletor, string $raio): void
    {
        self::assertMatchesRegularExpression('/border-radius:\s*' . preg_quote($raio, '/') . ';/', $this->regras($seletor));
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*999px/', $this->regras($seletor));
    }
}
