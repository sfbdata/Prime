<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\FiltroDosModelosInput;
use App\Pasta\Enum\BlocoDeMateria;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\OrdemDosModelos;
use App\Pasta\Enum\TipoDePeca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MateriaDeModelo::class)]
#[CoversClass(BlocoDeMateria::class)]
#[CoversClass(TipoDePeca::class)]
#[CoversClass(FiltroDosModelosInput::class)]
#[Group('pasta')]
final class ModeloDePecaEnumsTest extends TestCase
{
    #[TestDox('As listas são as do desenho: 16 matérias e 14 tipos')]
    public function testTamanhosDoDesenho(): void
    {
        self::assertCount(16, MateriaDeModelo::cases());
        self::assertCount(14, TipoDePeca::cases());
        self::assertCount(5, BlocoDeMateria::cases());
    }

    #[TestDox('Cada matéria está em exatamente um bloco e os blocos têm 8, 2, 3, 1 e 2 matérias')]
    public function testBlocos(): void
    {
        $soma = 0;
        foreach (BlocoDeMateria::cases() as $bloco) {
            $soma += count($bloco->materias());
        }
        self::assertSame(16, $soma);
        self::assertSame([8, 2, 3, 1, 2], array_map(static fn (BlocoDeMateria $b): int => count($b->materias()), BlocoDeMateria::cases()));
        self::assertSame('Execução e cobrança', MateriaDeModelo::ExecucaoECobranca->rotulo());
    }

    #[TestDox('Filtro da requisição: ★ é só favoritos, valor desconhecido vira sem filtro')]
    public function testFiltroDaRequisicao(): void
    {
        $fav = FiltroDosModelosInput::daRequisicao('★', 'parecer', '  cotas ', 'az');
        self::assertTrue($fav->soFavoritos);
        self::assertNull($fav->materia);
        self::assertSame(TipoDePeca::Parecer, $fav->tipo);
        self::assertSame('cotas', $fav->busca);
        self::assertSame(OrdemDosModelos::AaZ, $fav->ordem);

        $lixo = FiltroDosModelosInput::daRequisicao('xx', 'yy', null, 'zz');
        self::assertFalse($lixo->soFavoritos);
        self::assertNull($lixo->materia);
        self::assertNull($lixo->tipo);
        self::assertSame(OrdemDosModelos::MaisUsados, $lixo->ordem);
    }
}
