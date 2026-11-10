<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Diagramacao\DiagramacaoDeDuasColunas;
use App\AssinaturaEletronica\Pdf\Diagramacao\ElementoDaPagina;
use App\AssinaturaEletronica\Pdf\Diagramacao\LinhaDeTexto;
use App\AssinaturaEletronica\Pdf\Diagramacao\LugarDeAssinatura;
use App\AssinaturaEletronica\Pdf\Diagramacao\PaginaDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\Diagramacao\Palavra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Duas colunas (E3-9): a letra desce de 17,5 até 9,5, de 0,25 em 0,25, até tudo caber numa página; se nem em 9,5
 * couber, o texto continua na página seguinte.
 */
#[CoversClass(DiagramacaoDeDuasColunas::class)]
final class DiagramacaoDeDuasColunasTest extends TestCase
{
    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
    }

    #[TestDox('Texto curto fica em uma página na letra maior, 17,5')]
    public function testCurtoNaLetraMaior(): void
    {
        $paginas = (new DiagramacaoDeDuasColunas())->diagramar(FolhaDeTeste::paragrafos(3));

        self::assertCount(1, $paginas);
        self::assertSame([DiagramacaoDeDuasColunas::MAIOR], self::tamanhos($paginas));
    }

    #[TestDox('Texto que não cabe em 17,5 encolhe até caber numa página só')]
    public function testEncolhe(): void
    {
        $paginas = (new DiagramacaoDeDuasColunas())->diagramar([
            ['k' => 'p', 'x' => 'Contratante: Maria da Silva, brasileira, inscrita no CPF sob o nº 000.000.000-00.', 'full' => 1],
            ...FolhaDeTeste::paragrafos(14, 2),
            ['k' => 'a', 'i' => 0, 'r' => ''],
            ['k' => 'a', 'i' => 1, 'r' => ''],
        ]);

        self::assertCount(1, $paginas);
        $tamanhos = self::tamanhos($paginas);
        self::assertCount(1, $tamanhos, 'Introdução e colunas usam a mesma letra.');
        self::assertLessThan(DiagramacaoDeDuasColunas::MAIOR, $tamanhos[0]);
        self::assertGreaterThan(DiagramacaoDeDuasColunas::MENOR, $tamanhos[0]);
        self::assertSame(0.0, fmod(($tamanhos[0] - DiagramacaoDeDuasColunas::MENOR) * 4, 1.0), 'Passos de 0,25.');
        self::assertCount(2, array_filter($paginas[0], static fn (ElementoDaPagina $e): bool => $e instanceof LugarDeAssinatura && $e->mini));
        self::assertLessThanOrEqual(1250.0, max(array_map(static fn (LinhaDeTexto $l): float => $l->base, self::linhas($paginas[0]))));
    }

    #[TestDox('Texto que não cabe nem em 9,5 continua na segunda página, sem perder linha')]
    public function testTransbordaParaASegundaPagina(): void
    {
        $paginas = (new DiagramacaoDeDuasColunas())->diagramar(FolhaDeTeste::paragrafos(40, 3));

        self::assertCount(2, $paginas);
        self::assertSame([DiagramacaoDeDuasColunas::MENOR], self::tamanhos($paginas));
        self::assertNotEmpty(self::linhas($paginas[1]));

        $palavras = 0;
        foreach ($paginas as $pagina) {
            foreach (self::linhas($pagina) as $linha) {
                $palavras += \count($linha->palavras);
            }
        }
        $esperadas = 0;
        foreach (FolhaDeTeste::paragrafos(40, 3) as $bloco) {
            $esperadas += \count(preg_split('/\s+/u', trim((string) $bloco['x'])) ?: []);
        }
        self::assertSame($esperadas, $palavras);
    }

    #[TestDox('Número e título ficam fora das colunas; páginas do PDF enviado vêm no fim, cada uma na sua')]
    public function testForaDasColunas(): void
    {
        $paginas = (new DiagramacaoDeDuasColunas())->diagramar([
            ['k' => 'n', 'x' => '123', 'full' => 0],
            ['k' => 't', 'x' => 'CONTRATO', 'full' => 0],
            ['k' => 'pg', 'n' => 1],
            ...FolhaDeTeste::paragrafos(1),
            ['k' => 'pg', 'n' => 2],
        ]);

        self::assertCount(3, $paginas);
        self::assertNotEmpty(self::linhas($paginas[0]));
        self::assertEquals([new PaginaDoPdfEnviado(1)], $paginas[1]);
        self::assertEquals([new PaginaDoPdfEnviado(2)], $paginas[2]);
        foreach (self::linhas($paginas[0]) as $linha) {
            self::assertNotContains('CONTRATO', array_map(static fn (Palavra $p): string => $p->texto, $linha->palavras));
        }
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

    /**
     * @param list<list<ElementoDaPagina>> $paginas
     *
     * @return list<float>
     */
    private static function tamanhos(array $paginas): array
    {
        $tamanhos = [];
        foreach ($paginas as $pagina) {
            foreach (self::linhas($pagina) as $linha) {
                $tamanhos[] = $linha->tamanho;
            }
        }

        return array_values(array_unique($tamanhos, \SORT_REGULAR));
    }
}
