<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModeloDeIa::class)]
#[CoversClass(ModoDeCobrancaDaIa::class)]
final class ModeloDeIaTest extends TestCase
{
    #[TestDox('os três modelos do painel, com rótulo e preço por milhão de tokens; o padrão é o Haiku 5.5')]
    public function testModelos(): void
    {
        self::assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'], array_map(static fn (ModeloDeIa $m): string => $m->value, ModeloDeIa::cases()));
        self::assertSame(ModeloDeIa::Haiku55, ModeloDeIa::PADRAO);
        self::assertSame('Claude Haiku 5.5 (mais barato)', ModeloDeIa::Haiku55->rotulo());
        self::assertSame('Claude Sonnet 5.5', ModeloDeIa::Sonnet55->rotulo());
        self::assertSame('Claude Opus 5.5 (mais caro)', ModeloDeIa::Opus55->rotulo());
        self::assertSame(['entrada' => 0.10, 'saida' => 0.50], ModeloDeIa::Haiku55->precoPorMilhao());
        self::assertSame(['entrada' => 2.0, 'saida' => 10.0], ModeloDeIa::Sonnet55->precoPorMilhao());
        self::assertSame(['entrada' => 4.0, 'saida' => 20.0], ModeloDeIa::Opus55->precoPorMilhao());
    }

    #[TestDox('Fable e Mythos ficam fora (exigem retenção de 30 dias)')]
    public function testSemModelosComRetencao(): void
    {
        foreach (ModeloDeIa::cases() as $m) {
            self::assertStringNotContainsStringIgnoringCase('fable', $m->value);
            self::assertStringNotContainsStringIgnoringCase('mythos', $m->value);
        }
    }

    #[TestDox('os modos de cobrança e os rótulos, sem travessão')]
    public function testModosDeCobranca(): void
    {
        self::assertSame('Incluso no plano', ModoDeCobrancaDaIa::Incluso->rotulo());
        self::assertSame('Adicional pago', ModoDeCobrancaDaIa::Adicional->rotulo());
        self::assertSame('Limite grátis, depois cobra', ModoDeCobrancaDaIa::LimiteGratis->rotulo());
        self::assertSame('limite_gratis', ModoDeCobrancaDaIa::LimiteGratis->value);
        foreach ([...ModeloDeIa::cases(), ...ModoDeCobrancaDaIa::cases()] as $c) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $c->rotulo());
        }
    }
}
