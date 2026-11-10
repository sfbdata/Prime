<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Alinhamento;
use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDePaginaInteira;
use App\AssinaturaEletronica\Pdf\Diagramacao\ElementoDaPagina;
use App\AssinaturaEletronica\Pdf\Diagramacao\LinhaDeTexto;
use App\AssinaturaEletronica\Pdf\Diagramacao\LugarDeAssinatura;
use App\AssinaturaEletronica\Pdf\Diagramacao\PaginaDoPdfEnviado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A página inteira do `pdf()` (dc:395-397): de 170 a 1220, `garante(h)` abre página quando `y + h` passa de 1220;
 * depois de cada bloco, +2 no título, +22 no subtítulo, +4 no cabeçalho e +12 no resto; assinatura ocupa 112; `img`
 * (o `pg`) vai sozinho numa página. A linha de base fica 18 abaixo do topo da linha.
 */
#[CoversClass(DiagramacaoDePaginaInteira::class)]
final class DiagramacaoDePaginaInteiraTest extends TestCase
{
    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
    }

    #[TestDox('Espaço depois de cada bloco: +2 no título, +22 no subtítulo, +4 no cabeçalho, +12 no resto; assinatura de 112')]
    public function testEspacamentos(): void
    {
        $paginas = (new DiagramacaoDePaginaInteira())->diagramar([
            ['k' => 't', 'x' => 'PROCURAÇÃO', 'full' => 0],
            ['k' => 's', 'x' => 'Ad judicia', 'full' => 0],
            ['k' => 'h', 'x' => 'Outorgante', 'full' => 0],
            ['k' => 'p', 'x' => 'Texto curto.', 'full' => 0],
            ['k' => 'l', 'x' => 'Poderes', 'full' => 0],
            ['k' => 'd', 'x' => 'Brasília/DF.', 'full' => 0],
            ['k' => 'a', 'i' => 0, 'r' => ''],
            ['k' => 'p', 'x' => 'Depois.', 'full' => 0],
        ]);

        self::assertCount(1, $paginas);
        self::assertCount(8, $paginas[0]);
        $linhas = self::linhas($paginas[0]);
        // Título: 170 + 36 + 2 = 208; subtítulo: + 28,5 + 22 = 258,5; cabeçalho: + 28,5 + 4 = 291; os outros: + 28,5 + 12.
        // A assinatura entra em 412,5 e a linha seguinte começa 112 abaixo.
        self::assertSame([188.0, 226.0, 276.5, 309.0, 349.5, 390.0, 412.5 + 112 + 18], array_map(static fn (LinhaDeTexto $l): float => $l->base, $linhas));
        self::assertSame([26.0, 20.0, 20.0, 19.0, 20.0, 19.0, 19.0], array_map(static fn (LinhaDeTexto $l): float => $l->tamanho, $linhas));
        self::assertSame([Alinhamento::Centro, Alinhamento::Centro, Alinhamento::Direita], [$linhas[0]->alinhamento, $linhas[1]->alinhamento, $linhas[5]->alinhamento]);
        self::assertEquals(new LugarDeAssinatura(0, '', 412.5, 487.0, 200.0, false), self::assinatura($paginas[0]));
    }

    #[TestDox('Linha que não cabe até 1220 abre página nova, e o parágrafo segue justificado do outro lado')]
    public function testQuebraPeloGarantir(): void
    {
        $paginas = (new DiagramacaoDePaginaInteira())->diagramar(FolhaDeTeste::paragrafos(1, 40));

        self::assertGreaterThan(2, \count($paginas));
        $primeira = self::linhas($paginas[0]);
        // (1220 - 170) / 28,5 = 36,8: a 37ª linha terminaria em 1224,5.
        self::assertCount(36, $primeira);
        self::assertSame(188.0 + 35 * 28.5, $primeira[35]->base);
        self::assertTrue($primeira[35]->justificar, 'A quebra de página não é o fim do parágrafo.');
        self::assertSame(188.0, self::linhas($paginas[1])[0]->base);

        $ultimas = self::linhas($paginas[array_key_last($paginas)]);
        self::assertFalse($ultimas[array_key_last($ultimas)]->justificar, 'A última linha do parágrafo fica à esquerda.');
    }

    #[TestDox('Assinatura precisa de 112: cabe até 1220, senão vai para o alto da página seguinte')]
    public function testAssinaturaDe112(): void
    {
        // Cada parágrafo de uma linha ocupa 28,5 + 12 = 40,5.
        $cabe = (new DiagramacaoDePaginaInteira())->diagramar([...self::linhasCurtas(23), ['k' => 'a', 'i' => 0, 'r' => '']]);
        $naoCabe = (new DiagramacaoDePaginaInteira())->diagramar([...self::linhasCurtas(24), ['k' => 'a', 'i' => 0, 'r' => ''], ['k' => 'p', 'x' => 'Fim.', 'full' => 0]]);

        // 170 + 23 x 40,5 = 1101,5 e 1101,5 + 112 = 1213,5: cabe.
        self::assertCount(1, $cabe);
        self::assertSame(1101.5, self::assinatura($cabe[0])->y);

        // 170 + 24 x 40,5 = 1142 e 1142 + 112 = 1254: passa, a assinatura abre a página 2.
        self::assertCount(2, $naoCabe);
        self::assertCount(24, self::linhas($naoCabe[0]));
        self::assertSame(170.0, self::assinatura($naoCabe[1])->y);
        self::assertSame(170.0 + 112 + 18, self::linhas($naoCabe[1])[0]->base);
    }

    #[TestDox('Página do PDF enviado vai sozinha: abre página se não está no alto e fecha a sua depois')]
    public function testPaginaEnviadaSozinha(): void
    {
        $meio = (new DiagramacaoDePaginaInteira())->diagramar([
            ['k' => 'p', 'x' => 'Antes.', 'full' => 0],
            ['k' => 'pg', 'n' => 3],
            ['k' => 'p', 'x' => 'Depois.', 'full' => 0],
        ]);
        $seguidas = (new DiagramacaoDePaginaInteira())->diagramar([['k' => 'pg', 'n' => 1], ['k' => 'pg', 'n' => 2]]);

        self::assertCount(3, $meio);
        self::assertCount(1, self::linhas($meio[0]));
        self::assertEquals([new PaginaDoPdfEnviado(3)], $meio[1]);
        self::assertSame(188.0, self::linhas($meio[2])[0]->base);

        // No alto da página não abre outra antes; a vazia do fim sai no montador (dc:406).
        self::assertEquals([[new PaginaDoPdfEnviado(1)], [new PaginaDoPdfEnviado(2)], []], $seguidas);
    }

    /** @return list<array<string, int|string>> */
    private static function linhasCurtas(int $quantas): array
    {
        return array_fill(0, $quantas, ['k' => 'p', 'x' => 'Cláusula.', 'full' => 0]);
    }

    /**
     * @param list<ElementoDaPagina> $pagina
     *
     * @return list<LinhaDeTexto>
     */
    private static function linhas(array $pagina): array
    {
        return array_values(array_filter($pagina, static fn (ElementoDaPagina $e): bool => $e instanceof LinhaDeTexto));
    }

    /** @param list<ElementoDaPagina> $pagina */
    private static function assinatura(array $pagina): LugarDeAssinatura
    {
        $lugares = array_values(array_filter($pagina, static fn (ElementoDaPagina $e): bool => $e instanceof LugarDeAssinatura));
        self::assertCount(1, $lugares);

        return $lugares[0];
    }
}
