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

    // ──────────────────────────────────────────────────────────────────
    // abonaSaldo() — quem pode zerar o saldo negativo do dia
    // Ver docs/specs/ponto-abono-nao-perdoa-jornada.md
    // ──────────────────────────────────────────────────────────────────

    /**
     * Esta é a regra que o dono pediu: o esquecimento repõe a batida na aprovação e para por aí.
     * O déficit que sobra depois disso é atraso ou saída antecipada — em 18/06/2026 o abono apagou
     * 43 min de atraso e 48 min de saída antecipada de uma vez.
     */
    public function testCategoriaTecnicaNaoAbonaSaldo(): void
    {
        foreach (TipoJustificativa::cases() as $case) {
            if ($case->categoria() !== CategoriaJustificativa::Tecnica) {
                continue;
            }

            self::assertFalse($case->abonaSaldo(), sprintf('%s é técnica e não pode abonar', $case->value));
        }

        // âncora explícita: se alguém tirar EsquecimentoRegistro da categoria Técnica, o laço acima
        // passaria vazio e o teste ficaria cego.
        self::assertFalse(TipoJustificativa::EsquecimentoRegistro->abonaSaldo());
        self::assertFalse(TipoJustificativa::RegistroIncorreto->abonaSaldo());
        self::assertFalse(TipoJustificativa::CorrecaoPonto->abonaSaldo());
    }

    public function testFaltaNaoJustificadaNaoAbonaSaldo(): void
    {
        self::assertFalse(TipoJustificativa::FaltaNaoJustificada->abonaSaldo());
    }

    public function testDemaisCategoriasContinuamAbonando(): void
    {
        self::assertTrue(TipoJustificativa::AtestadoMedico->abonaSaldo());       // legal
        self::assertTrue(TipoJustificativa::DispensaAbonada->abonaSaldo());      // operacional
        self::assertTrue(TipoJustificativa::AjusteJornada->abonaSaldo());        // operacional
        self::assertTrue(TipoJustificativa::ProblemaTransporte->abonaSaldo());   // intercorrência
        self::assertTrue(TipoJustificativa::Plantao->abonaSaldo());              // regime especial
        self::assertTrue(TipoJustificativa::AbandonoPosto->abonaSaldo());        // crítica ≠ FaltaNaoJustificada
    }
}
