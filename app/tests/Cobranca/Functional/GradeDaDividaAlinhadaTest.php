<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 🔴 O cabeçalho de colunas (`.jp-lista-head`) e as linhas (`.jp-obr`) da aba Dívida são grades CSS
 * SEPARADAS que precisam alinhar coluna a coluna. Nada na suíte enxerga isso: PHPUnit lê HTML, não
 * posição — o defeito que originou este teste (28/08) tinha marcação impecável, DomCrawler achava
 * todos os seletores, e na tela `ORIGINAL`/`ACRÉSCIMOS`/`TOTAL` apareciam ~250px à direita dos
 * próprios números.
 *
 * A causa foi um track `minmax(0, auto)` na coluna de ações: ele mede 0 no cabeçalho (célula vazia) e
 * ~265px na linha (os três botões), e o `1fr` da descrição absorve sobras diferentes em cada grade.
 *
 * Este teste lê a FOLHA e trava as três condições que garantem o alinhamento. É teste de CSS de
 * propósito: é onde o defeito mora, e não há como observá-lo pelo HTML.
 */
final class GradeDaDividaAlinhadaTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/cobrancas.css';

    /** A declaração que vale para as DUAS grades, extraída do bloco que as declara juntas. */
    private function blocoDaGrade(): string
    {
        $css = (string) file_get_contents(self::CSS);

        $ok = preg_match(
            '/#secao-divida \.jp-lista-head,\s*\n#secao-divida \.jp-obr:not\(\.is-substituida\) \{(.*?)\n\}/s',
            $css,
            $m,
        );

        self::assertSame(
            1,
            $ok,
            'O cabeçalho e a linha da dívida têm de declarar a grade NO MESMO BLOCO. Separá-los é o '
            . 'primeiro passo para os dois divergirem sem ninguém notar.',
        );

        return $m[1];
    }

    #[Test]
    #[TestDox('Cabeçalho e linha declaram a MESMA grade, no mesmo bloco')]
    public function asDuasGradesSaoDeclaradasJuntas(): void
    {
        self::assertStringContainsString('grid-template-columns:', $this->blocoDaGrade());
    }

    #[Test]
    #[TestDox('Só a coluna da descrição é flexível — as outras sete são de largura fixa')]
    public function apenasADescricaoEFlexivel(): void
    {
        preg_match('/grid-template-columns:([^;]+);/', $this->blocoDaGrade(), $m);
        $tracks = preg_split('/\s+(?![^(]*\))/', trim($m[1])) ?: [];

        self::assertCount(8, $tracks, 'check · venceu · o que é · Original · Acréscimos · Total · ações · chevron');

        $flexiveis = array_values(array_filter($tracks, static fn (string $t): bool => str_contains($t, 'fr')));
        self::assertCount(
            1,
            $flexiveis,
            'Exatamente UM track pode ser flexível. Com dois, a sobra se reparte por proporção e as duas '
            . 'grades — que têm conteúdos diferentes — resolvem larguras diferentes.',
        );
        self::assertStringContainsString('7rem', $flexiveis[0], 'o flexível é a descrição, com piso');

        // `auto`, `min-content` e `max-content` medem o CONTEÚDO — e o conteúdo do cabeçalho não é o
        // mesmo da linha. Foi exatamente `minmax(0, auto)` nas ações que desalinhou a tela.
        foreach ($tracks as $i => $track) {
            if (str_contains($track, 'fr')) {
                continue;
            }
            foreach (['auto', 'min-content', 'max-content', 'fit-content'] as $proibido) {
                self::assertStringNotContainsString(
                    $proibido,
                    $track,
                    "O track {$i} (\"{$track}\") depende do conteúdo. Numa das duas grades a célula é "
                    . 'vazia, então ele mede zero lá e não mede zero aqui — as colunas saem tortas.',
                );
            }
        }
    }

    #[Test]
    #[TestDox('As duas grades têm o mesmo padding horizontal — senão a origem delas difere')]
    public function oPaddingHorizontalEOMesmoNasDuas(): void
    {
        $bloco = $this->blocoDaGrade();

        // Tracks idênticos não bastam: a régua começa depois do padding, e a regra base dá .65rem ao
        // cabeçalho enquanto a linha usa 1rem. A correção mora no bloco COMUM, e é aqui que ela fica.
        self::assertMatchesRegularExpression('/padding-left:\s*[^;]+;/', $bloco, 'o bloco comum fixa o padding-left');
        self::assertMatchesRegularExpression('/padding-right:\s*[^;]+;/', $bloco, 'e o padding-right');

        preg_match('/padding-left:\s*([^;]+);/', $bloco, $esq);
        preg_match('/padding-right:\s*([^;]+);/', $bloco, $dir);
        self::assertSame(trim($esq[1]), trim($dir[1]), 'os dois lados iguais — a fila é simétrica');
    }

    #[Test]
    #[TestDox('O corte do cartão empilhado cobre a largura mínima que a fila passou a ter')]
    public function oCorteDoEmpilhadoCobreALarguraMinimaDaFila(): void
    {
        $css = (string) file_get_contents(self::CSS);

        // Com a coluna de ações fixa a fila tem piso MEDIDO: 34 + 108 + 112 + 110 + 120 + 130 + 216 +
        // 34 = 864, mais 32 de padding = 896px. Abaixo disso ela estoura em rolagem horizontal, que é
        // o que o cartão empilhado existe para evitar. O corte tem de ficar ACIMA desse piso.
        $ok = preg_match('/@container divida \(max-width: (\d+)px\) \{\s*\n\s*#secao-divida \.jp-lista-head/', $css, $m);
        self::assertSame(1, $ok, 'a container query que empilha a fila tem de existir');

        self::assertGreaterThanOrEqual(
            896,
            (int) $m[1],
            'O corte ficou ABAIXO da largura mínima da fila: entre os dois valores a linha estoura em '
            . 'rolagem horizontal em vez de virar cartão.',
        );
    }
}
