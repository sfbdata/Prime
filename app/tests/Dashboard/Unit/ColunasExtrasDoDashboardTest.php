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

    #[TestDox('Tendência: concluídas, taxa, pastas concluídas e relatório (subir é bom), tempo médio (cair é bom) e eventos (neutra); Em revisão e Pastas urgentes ficam fora (estoque sem foto)')]
    public function testTendencia(): void
    {
        self::assertSame(
            [
                Extras::PASTAS_CONCLUIDAS => 'alta',
                Extras::EVENTOS_AGENDA    => 'fila',
                Extras::RELATORIO         => 'alta',
                Extras::METAS_CONCLUIDAS  => 'alta',
                Extras::TAXA_CONCLUSAO    => 'alta',
                Extras::TEMPO_MEDIO       => 'baixa',
            ],
            Extras::TENDENCIA,
        );
        self::assertSame(
            [Extras::METAS_CONCLUIDAS, Extras::EVENTOS_AGENDA, Extras::TEMPO_MEDIO, Extras::TAXA_CONCLUSAO],
            Extras::comTendencia([Extras::METAS_CONCLUIDAS, Extras::EVENTOS_AGENDA, Extras::METAS_REVISAO, Extras::TEMPO_MEDIO, Extras::PASTAS_URGENTES, Extras::TAXA_CONCLUSAO]),
            'na ordem recebida, sem as de estoque',
        );
        self::assertSame([], Extras::comTendencia([Extras::METAS_REVISAO, Extras::PASTAS_URGENTES]));
    }

    #[TestDox('Sentido do desenho: Tempo médio é sobeRuim (baixa); Em revisão (neutra) e Pastas urgentes (sobeRuim) não têm tendência por falta de foto')]
    public function testSentidoDoDesenhoEEstoqueSemSeta(): void
    {
        self::assertSame('baixa', Extras::TENDENCIA[Extras::TEMPO_MEDIO]);
        foreach ([Extras::METAS_REVISAO, Extras::PASTAS_URGENTES] as $coluna) {
            self::assertArrayNotHasKey($coluna, Extras::TENDENCIA, $coluna);
        }
    }

    #[TestDox('Pastas concluídas: rótulo e ajuda do desenho (dc EXTRAS.pastasConcluidas), soma, entre Pastas urgentes e Eventos na agenda')]
    public function testPastasConcluidasComoNoDesenho(): void
    {
        $c = Extras::CATALOGO[Extras::PASTAS_CONCLUIDAS];

        self::assertSame('pastas_concluidas', Extras::PASTAS_CONCLUIDAS);
        self::assertSame(['Pastas concluídas', 'Demandas encerradas no período', Extras::TIPO_SOMA], [$c['rotulo'], $c['ajuda'], $c['tipo']]);
        $chaves = Extras::chaves();
        self::assertSame(array_search(Extras::PASTAS_URGENTES, $chaves, true) + 1, array_search(Extras::PASTAS_CONCLUIDAS, $chaves, true));
        self::assertSame(array_search(Extras::EVENTOS_AGENDA, $chaves, true) - 1, array_search(Extras::PASTAS_CONCLUIDAS, $chaves, true));
    }
}
