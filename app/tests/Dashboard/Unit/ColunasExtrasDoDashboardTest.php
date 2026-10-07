<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As ressalvas que o número sozinho não conta, na `nota` (title do cabeçalho) de cada coluna extra.
 */
#[CoversClass(Extras::class)]
final class ColunasExtrasDoDashboardTest extends TestCase
{
    #[TestDox('Eventos na agenda: a nota diz que o recorrente conta uma vez, pela ocorrência-base')]
    public function testNotaDosEventosExplicaORecorrente(): void
    {
        $nota = Extras::CATALOGO[Extras::EVENTOS_AGENDA]['nota'];

        self::assertStringContainsString('recorrente conta uma vez, pela ocorrência-base', $nota);
    }

    #[TestDox('Tendência só nas extras com lastro por data, com o sentido do desenho; Em revisão e Pastas urgentes (estoque) ficam fora')]
    public function testTendencia(): void
    {
        self::assertSame([
            Extras::METAS_CONCLUIDAS => 'alta',
            Extras::TAXA_CONCLUSAO   => 'alta',
            Extras::TEMPO_MEDIO      => 'baixa',
            Extras::EVENTOS_AGENDA   => 'fila',
        ], Extras::TENDENCIA);
        self::assertSame(
            [Extras::EVENTOS_AGENDA, Extras::TEMPO_MEDIO, Extras::METAS_CONCLUIDAS],
            Extras::comTendencia([Extras::EVENTOS_AGENDA, Extras::METAS_REVISAO, Extras::TEMPO_MEDIO, Extras::PASTAS_URGENTES, Extras::METAS_CONCLUIDAS]),
            'na ordem recebida, sem as de estoque',
        );
        self::assertSame([], Extras::comTendencia([Extras::METAS_REVISAO, Extras::PASTAS_URGENTES]));
    }
}
