<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Enum\Automacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Automacao::class)]
final class AutomacaoTest extends TestCase
{
    #[TestDox('As 8 automações na ordem do desenho 05 (dc L.562), só a `dica` nasce desligada, só o `cad` é obrigatório')]
    public function testPadroesDoDesenho(): void
    {
        self::assertSame(['cad', 'lemb', 'val', 'conv', 'dica', 'e90', 'e100', 'ped'], array_map(static fn (Automacao $a): string => $a->value, Automacao::cases()));
        self::assertSame(['dica'], array_values(array_map(static fn (Automacao $a): string => $a->value, array_filter(Automacao::cases(), static fn (Automacao $a): bool => !$a->ligadaPorPadrao()))));
        self::assertSame(['cad'], array_values(array_map(static fn (Automacao $a): string => $a->value, array_filter(Automacao::cases(), static fn (Automacao $a): bool => $a->obrigatoria()))));
    }

    #[TestDox('Atrasos do dc: 24/48/72 h no lembrete, 3/7/15 dias na dica, 80/85/90% no espaço; o padrão é 24h, 7d e 90')]
    public function testAtrasos(): void
    {
        self::assertSame(['24h', '48h', '72h'], Automacao::Lemb->opcoesDeAtraso());
        self::assertSame(['3d', '7d', '15d'], Automacao::Dica->opcoesDeAtraso());
        self::assertSame(['80', '85', '90'], Automacao::E90->opcoesDeAtraso());
        self::assertSame([], Automacao::Val->opcoesDeAtraso());
        self::assertSame(['24h', '7d', '90'], [Automacao::Lemb->atrasoPadrao(), Automacao::Dica->atrasoPadrao(), Automacao::E90->atrasoPadrao()]);
    }

    #[TestDox('Títulos, descrições e gatilhos de "Últimos disparos" com o texto do desenho')]
    public function testTextos(): void
    {
        self::assertSame('Ao abrir uma conta', Automacao::Cad->titulo());
        self::assertSame('Envia o link e o código para confirmar o e-mail', Automacao::Cad->descricao());
        self::assertSame('Conta aberta', Automacao::Cad->gatilho());
        self::assertSame('E-mail confirmado', Automacao::Val->gatilho());
        self::assertSame('Convite aceito (secundário)', Automacao::Conv->titulo());
        self::assertSame('automacao:dica', Automacao::Dica->origem());
    }
}
