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

    #[TestDox('Tendência só em Eventos na agenda (data real, neutra); estoque e as três por criação + status de hoje ficam fora')]
    public function testTendencia(): void
    {
        self::assertSame([Extras::EVENTOS_AGENDA => 'fila'], Extras::TENDENCIA);
        self::assertSame(
            [Extras::EVENTOS_AGENDA],
            Extras::comTendencia([Extras::METAS_CONCLUIDAS, Extras::EVENTOS_AGENDA, Extras::METAS_REVISAO, Extras::TEMPO_MEDIO, Extras::PASTAS_URGENTES, Extras::TAXA_CONCLUSAO]),
            'na ordem recebida, só eventos',
        );
        self::assertSame([], Extras::comTendencia([Extras::METAS_REVISAO, Extras::PASTAS_URGENTES]));
    }

    #[TestDox('DASH-010b: concluídas, taxa e tempo médio sem tendência — recortam por criação e leem o status de hoje')]
    public function testSemTendenciaAsTresPorCriacao(): void
    {
        foreach ([Extras::METAS_CONCLUIDAS, Extras::TAXA_CONCLUSAO, Extras::TEMPO_MEDIO] as $coluna) {
            self::assertArrayNotHasKey($coluna, Extras::TENDENCIA, $coluna);
            self::assertSame([], Extras::comTendencia([$coluna]), $coluna);
        }
    }
}
