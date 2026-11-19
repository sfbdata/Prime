<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guia visual v30 (frente A de 19/11/2026) em o Trabalho Externo: linha sob o cursor em #2b7ab0 com faixa interna de 4px
 * #1f6f9c e texto branco; pílula vira raio 4px; círculos e interruptor ficam redondos.
 * Que a linha fica azul na tela é o smoke do dono; aqui se prova que a regra está no seletor.
 */
#[CoversNothing]
final class TrabalhoExternoRealceDaLinhaCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/trabalho-externo.css';

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
        yield 'demanda' => ['.te-linha:hover'];
        yield 'demanda selecionada' => ['.te-linha.is-sel:hover'];
        yield 'demanda com teclado' => ['.te-linha:focus-visible'];
        yield 'profissional' => ['.te-cv-linha:hover'];
        yield 'profissional selecionado' => ['.te-cv-linha.is-sel:hover'];
        yield 'menu' => ['.te-hm-item:hover'];
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
        yield 'texto da demanda' => ['.te-linha:hover .te-l-id'];
        yield 'prazo inline' => ['.te-linha:hover .te-l-prazo-txt'];
        yield 'situação inline' => ['.te-linha:hover .te-l-sit > span'];
        yield 'nome do profissional' => ['.te-cv-linha:hover .te-cv-nome'];
        yield 'cargo' => ['.te-cv-linha:hover .te-cv-cargo'];
    }

    #[DataProvider('textosBrancos')]
    #[TestDox('texto branco na linha realçada: $seletor')]
    public function testTextoBranco(string $seletor): void
    {
        self::assertMatchesRegularExpression('/color:\s*#fff/', $this->regras($seletor));
    }

    #[TestDox('o contador de mensagens da linha vira branco com número azul no realce')]
    public function testContadorVisivelNoRealce(): void
    {
        $corpo = $this->regras('.te-linha:hover .te-l-chat-n');
        self::assertMatchesRegularExpression('/background:\s*#fff;/', $corpo);
        self::assertMatchesRegularExpression('/color:\s*#2b7ab0;/', $corpo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function raios(): iterable
    {
        yield 'contador de mensagens' => ['.te-l-chat-n', '4px'];
        yield 'contador da aba' => ['.te-dz-aba-n', '4px'];
        yield 'trilho do interruptor (fica)' => ['.te-dp-chave-trilho', '10px'];
        yield 'bola do interruptor (fica)' => ['.te-dp-chave-bola', '50%'];
        yield 'avatar (fica)' => ['.te-cv-av', '50%'];
    }

    #[DataProvider('raios')]
    #[TestDox('canto do guia v30: $seletor = $raio')]
    public function testCantos(string $seletor, string $raio): void
    {
        self::assertMatchesRegularExpression('/border-radius:\s*' . preg_quote($raio, '/') . ';/', $this->regras($seletor));
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*999px/', $this->regras($seletor));
    }
}
