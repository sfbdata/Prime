<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Service\Marcadores;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Marcadores do artigo: slug, limpeza do que vem do editor e os limites (8 por artigo, 40 caracteres cada). */
#[CoversClass(Marcadores::class)]
final class MarcadoresTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function slugs(): iterable
    {
        yield 'espaço vira hífen' => ['Prazo em dobro', 'prazo-em-dobro'];
        yield 'acento sai' => ['Petição Inicial', 'peticao-inicial'];
        yield 'maiúsculas viram minúsculas' => ['LGPD', 'lgpd'];
        yield 'espaço nas pontas não conta' => ['  Recesso forense  ', 'recesso-forense'];
        yield 'cedilha e til' => ['Ação de cobrança', 'acao-de-cobranca'];
        yield 'só pontuação fica vazio' => ['!!!', ''];
    }

    #[DataProvider('slugs')]
    #[TestDox('Slug de "$marcador" é "$esperado"')]
    public function testSlug(string $marcador, string $esperado): void
    {
        self::assertSame($esperado, Marcadores::slug($marcador));
    }

    #[TestDox('Normalizar tira espaço das pontas e o duplo no meio, sem mudar a grafia')]
    public function testNormalizarEspacos(): void
    {
        self::assertSame(['Prazo em dobro', 'LGPD'], Marcadores::normalizar(["  Prazo   em\tdobro ", 'LGPD ']));
    }

    #[TestDox('Normalizar descarta vazio, só espaço e o que não é texto')]
    public function testNormalizarDescartaVazioENaoTexto(): void
    {
        self::assertSame(['Prazos'], Marcadores::normalizar(['', '   ', null, 12, ['x'], true, 'Prazos']));
        self::assertSame([], Marcadores::normalizar([]));
    }

    #[TestDox('Repetido pelo slug conta como um: fica a primeira grafia, na ordem em que vieram')]
    public function testNormalizarRepetidoFicaAPrimeira(): void
    {
        self::assertSame(
            ['Petição inicial', 'Prazos', 'Recesso'],
            Marcadores::normalizar(['Petição inicial', 'Prazos', 'PETICAO INICIAL', 'peticao-inicial', 'Recesso', 'prazos']),
        );
    }

    #[TestDox('Normalizar não corta nem recusa: os limites ficam para erros()')]
    public function testNormalizarNaoCorta(): void
    {
        $longo = str_repeat('a', 50);
        $nove = ['um', 'dois', 'tres', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];

        self::assertSame([$longo], Marcadores::normalizar([$longo]));
        self::assertSame($nove, Marcadores::normalizar($nove));
    }

    #[TestDox('Até 8 marcadores de até 40 caracteres (acento conta como um): sem erro')]
    public function testSemErros(): void
    {
        $oito = ['um', 'dois', 'tres', 'quatro', 'cinco', 'seis', 'sete', str_repeat('é', 40)];

        self::assertSame([], Marcadores::erros($oito));
        self::assertSame([], Marcadores::erros([]));
    }

    #[TestDox('Mais de 8 marcadores: uma mensagem com o limite')]
    public function testMaisDeOito(): void
    {
        self::assertSame(['Use até 8 marcadores.'], Marcadores::erros(['1', '2', '3', '4', '5', '6', '7', '8', '9']));
    }

    #[TestDox('Marcador com mais de 40 caracteres: a mensagem cita o marcador')]
    public function testMaisDeQuarentaCaracteres(): void
    {
        $longo = str_repeat('ã', 41);

        self::assertSame([sprintf('O marcador "%s" passa de 40 caracteres.', $longo)], Marcadores::erros(['Prazos', $longo]));
    }

    #[TestDox('Marcador sem letra nem número (slug vazio): a mensagem cita o marcador')]
    public function testSlugVazio(): void
    {
        self::assertSame(['O marcador "!!!" precisa ter letras ou números.'], Marcadores::erros(['!!!']));
    }

    #[TestDox('Vários problemas: uma mensagem por problema, a contagem primeiro')]
    public function testVariosErros(): void
    {
        $longo = str_repeat('x', 41);
        $erros = Marcadores::erros(['1', '2', '3', '4', '5', '6', '7', '???', $longo]);

        self::assertSame([
            'Use até 8 marcadores.',
            'O marcador "???" precisa ter letras ou números.',
            sprintf('O marcador "%s" passa de 40 caracteres.', $longo),
        ], $erros);
    }
}
