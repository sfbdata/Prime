<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TipoDeApenso::class)]
#[Group('pasta')]
final class TipoDeApensoTest extends TestCase
{
    #[TestDox('As opções são as do desenho (pvVals.rels, N:3141), na mesma ordem e com o rótulo exato')]
    public function testOpcoesDoDesenho(): void
    {
        self::assertSame(
            ['Embargos', 'Agravo de instrumento', 'Cumprimento de sentença', 'Execução', 'Impugnação', 'Recurso', 'Cautelar', 'Outro'],
            array_values(TipoDeApenso::opcoes()),
        );
        self::assertSame(TipoDeApenso::Embargos, TipoDeApenso::cases()[0], 'Embargos é o primeiro (padrão do select)');
    }

    #[TestDox('O valor gravado cabe na coluna (varchar 40) e volta para o mesmo caso')]
    public function testValoresCabemNaColuna(): void
    {
        foreach (TipoDeApenso::cases() as $tipo) {
            self::assertLessThanOrEqual(40, strlen($tipo->value));
            self::assertSame($tipo, TipoDeApenso::from($tipo->value));
        }
    }

    #[TestDox('Vínculo sem tipo gravado (antigo) é lido como "Outro"')]
    public function testVinculoSemTipoEhOutro(): void
    {
        $vinculo = new PastaProcesso(new Pasta(), new Processo());

        self::assertNull($vinculo->getTipoApenso());
        self::assertSame('Outro', $vinculo->getTipoApensoRotulo());

        $vinculo->setTipoApenso(TipoDeApenso::CumprimentoDeSentenca);
        self::assertSame('Cumprimento de sentença', $vinculo->getTipoApensoRotulo());
    }
}
