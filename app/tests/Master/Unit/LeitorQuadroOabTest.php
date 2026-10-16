<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Fontes\FonteIndisponivel;
use App\Master\Fontes\LeitorQuadroOab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorQuadroOab::class)]
final class LeitorQuadroOabTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/oab-quadro-advogados-2026-10-16.html';

    #[TestDox('Lê as 3 tabelas da página real da OAB (retrato de 16/10/2026)')]
    public function testLePaginaReal(): void
    {
        $lido = (new LeitorQuadroOab())->ler((string) file_get_contents(self::FIXTURE));

        self::assertCount(27, $lido['uf']);
        self::assertSame(1512836, $lido['totalAdvogados']);
        self::assertSame(396761, $lido['uf']['SP'][0]);
        self::assertSame(4250, $lido['uf']['AC'][0]);
        self::assertSame(1512836, array_sum(array_map(static fn (array $v): int => (int) $v[0], $lido['uf'])));

        // AC: estagiários, suplementares, consultores (quantitativo)
        self::assertSame([4250, 41, 597, 0], array_slice($lido['uf']['AC'], 0, 4));
        // AC: advogadas e advogados (gênero)
        self::assertSame([2046, 2204], array_slice($lido['uf']['AC'], 4, 2));
        // AC: até 25 anos F/M e 26 a 40 F (faixa etária)
        self::assertSame([118, 72, 1025], array_slice($lido['uf']['AC'], 6, 3));

        foreach ($lido['uf'] as $uf => $valores) {
            self::assertNotContains(null, $valores, 'todas as posições de ' . $uf . ' foram lidas');
        }
    }

    /**
     * @return iterable<string, array{0: callable(string): string}>
     */
    public static function adulteracoes(): iterable
    {
        yield 'coluna deslocada (célula de advogados do AC sumiu)' => [
            static fn (string $h): string => (string) preg_replace('/<td>\s*4\.250\s*<\/td>/', '', $h, 1),
        ];
        yield 'advogados zerados no AC, mesmo com o TOTAL ajustado' => [
            static fn (string $h): string => str_replace('1.512.836', '1.508.586', (string) preg_replace('/(<td>\s*)4\.250(\s*<\/td>)/', '${1}0${2}', $h, 1)),
        ];
        yield 'sigla inválida no lugar do AC' => [
            static fn (string $h): string => (string) preg_replace('/(<th scope="row" class="column1">\s*)AC(\s*<\/th>)/', '${1}XX${2}', $h, 1),
        ];
        yield 'número trocado sem mexer no TOTAL' => [
            static fn (string $h): string => (string) preg_replace('/(<td>\s*)4\.250(\s*<\/td>)/', '${1}4.251${2}', $h, 1),
        ];
    }

    /**
     * @param callable(string): string $adulterar
     */
    #[DataProvider('adulteracoes')]
    #[TestDox('Retrato que não fecha com a página é recusado: $_dataName')]
    public function testRecusaPaginaAdulterada(callable $adulterar): void
    {
        $original = (string) file_get_contents(self::FIXTURE);
        $html = $adulterar($original);
        self::assertNotSame($original, $html, 'a adulteração precisa ter mudado a página');

        $this->expectException(FonteIndisponivel::class);
        (new LeitorQuadroOab())->ler($html);
    }

    #[TestDox('Página sem o quadro (layout mudou, página de erro) falha em vez de gravar dado parcial')]
    public function testPaginaSemQuadroFalha(): void
    {
        $this->expectException(FonteIndisponivel::class);

        (new LeitorQuadroOab())->ler('<html><body><p>Manutenção</p></body></html>');
    }
}
