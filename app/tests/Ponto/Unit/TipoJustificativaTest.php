<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Enum\CategoriaJustificativa;
use App\Ponto\Enum\TipoJustificativa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TipoJustificativa::class)]
final class TipoJustificativaTest extends TestCase
{
    public function testDispensaAbonadaTemLabelCategoriaEValor(): void
    {
        $tipo = TipoJustificativa::DispensaAbonada;

        self::assertSame('dispensa_abonada', $tipo->value);
        self::assertSame('Dispensa Abonada', $tipo->label());
        self::assertSame(CategoriaJustificativa::Operacional, $tipo->categoria());
        self::assertContains('dispensa_abonada', TipoJustificativa::valores());
    }

    public function testSistemaIndisponivelTemLabelCategoriaEValor(): void
    {
        $tipo = TipoJustificativa::SistemaIndisponivel;

        self::assertSame('sistema_indisponivel', $tipo->value);
        self::assertSame('Sistema Indisponível', $tipo->label());
        self::assertSame(CategoriaJustificativa::Intercorrencia, $tipo->categoria());
        self::assertContains('sistema_indisponivel', TipoJustificativa::valores());
    }

    public function testNovosTiposAparecemNoGrupoCorretoDoSelect(): void
    {
        $grupos = TipoJustificativa::asGroupedChoices();

        self::assertArrayHasKey('Dispensa Abonada', $grupos['Operacionais']);
        self::assertSame('dispensa_abonada', $grupos['Operacionais']['Dispensa Abonada']);

        self::assertArrayHasKey('Sistema Indisponível', $grupos['Intercorrências']);
        self::assertSame('sistema_indisponivel', $grupos['Intercorrências']['Sistema Indisponível']);
    }

    /**
     * Guarda a exaustividade dos `match` de label() e categoria(): se um case
     * novo for declarado sem cobrir os dois matches, isto lança \UnhandledMatchError.
     */
    public function testLabelECategoriaCobremTodosOsCases(): void
    {
        foreach (TipoJustificativa::cases() as $case) {
            self::assertNotSame('', $case->label());
            self::assertInstanceOf(CategoriaJustificativa::class, $case->categoria());
        }
    }
}
