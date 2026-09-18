<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\OrcamentoDeMemoriaDeImagem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conta que impede o Fatal error do GD (E2.6A, D28).
 *
 * Os números vêm de medição no container: com `memory_limit` de 128 MB e ~4 MB em uso, 30 milhões de
 * pixels decodificam e 49 milhões matam o processo. A conta tem de dizer "não" ANTES, e com folga —
 * numa requisição real o Symfony já ocupa a parte dele, e estourar não é exceção: é morte.
 */
#[CoversClass(OrcamentoDeMemoriaDeImagem::class)]
final class OrcamentoDeMemoriaDeImagemTest extends TestCase
{
    private const MB = 1024 * 1024;

    /** @return iterable<string, array{bool, int, int, int, int}> */
    public static function casos(): iterable
    {
        // [cabe?, largura, altura, limite, em uso]
        yield 'foto comum com 128M livres'        => [true, 3000, 2000, 128 * self::MB, 4 * self::MB];
        yield 'digitalização A4 600dpi em 128M'   => [false, 4960, 7016, 128 * self::MB, 4 * self::MB];
        yield '49 milhões de pixels em 128M'      => [false, 7000, 7000, 128 * self::MB, 4 * self::MB];
        yield 'a mesma imagem com 1G'             => [true, 7000, 7000, 1024 * self::MB, 4 * self::MB];
        yield 'cabe no limite, mas já está cheio' => [false, 3000, 2000, 128 * self::MB, 120 * self::MB];
        yield 'reserva respeitada'                => [false, 1000, 1000, 12 * self::MB, 0];
    }

    #[DataProvider('casos')]
    #[TestDox('$_dataName')]
    public function testCabe(bool $esperado, int $largura, int $altura, int $limite, int $emUso): void
    {
        self::assertSame($esperado, OrcamentoDeMemoriaDeImagem::cabe($largura, $altura, $limite, $emUso));
    }

    #[TestDox('sem limite de memória (-1), qualquer imagem cabe')]
    public function testSemLimiteCabe(): void
    {
        self::assertTrue(OrcamentoDeMemoriaDeImagem::cabe(20000, 20000, -1, 0));
    }

    #[TestDox('dimensão inválida não cabe — nada a decodificar')]
    public function testDimensaoInvalida(): void
    {
        self::assertFalse(OrcamentoDeMemoriaDeImagem::cabe(0, 100, 128 * self::MB, 0));
        self::assertFalse(OrcamentoDeMemoriaDeImagem::cabe(100, -1, 128 * self::MB, 0));
    }

    /**
     * A conta só vale se o limite lido do PHP estiver certo: "128M" são 134217728 bytes, não 128.
     */
    #[TestDox('o memory_limit é lido com o sufixo, e não como número solto')]
    public function testLimiteDoProcesso(): void
    {
        self::assertSame(128 * self::MB, OrcamentoDeMemoriaDeImagem::limiteDoProcesso('128M'));
        self::assertSame(1024 * self::MB, OrcamentoDeMemoriaDeImagem::limiteDoProcesso('1G'));
        self::assertSame(64 * self::MB, OrcamentoDeMemoriaDeImagem::limiteDoProcesso('65536K'));
        self::assertSame(134217728, OrcamentoDeMemoriaDeImagem::limiteDoProcesso('134217728'));
        self::assertSame(134217728, OrcamentoDeMemoriaDeImagem::limiteDoProcesso(' 128m '));
        self::assertSame(-1, OrcamentoDeMemoriaDeImagem::limiteDoProcesso('-1'));
        self::assertSame(-1, OrcamentoDeMemoriaDeImagem::limiteDoProcesso(''));
    }

    #[TestDox('sem valor informado, o limite vem do próprio processo')]
    public function testLimiteVemDoProcesso(): void
    {
        self::assertSame(
            OrcamentoDeMemoriaDeImagem::limiteDoProcesso((string) ini_get('memory_limit')),
            OrcamentoDeMemoriaDeImagem::limiteDoProcesso(),
        );
    }

    #[TestDox('sem números injetados, a conta usa o limite e o uso reais do processo')]
    public function testUsaOProcessoQuandoNadaEhInjetado(): void
    {
        self::assertTrue(OrcamentoDeMemoriaDeImagem::cabe(10, 10));
        self::assertFalse(
            OrcamentoDeMemoriaDeImagem::cabe(100_000, 100_000),
            'dez bilhões de pixels nunca cabem no limite de teste (512M)',
        );
    }
}
