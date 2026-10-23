<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Ranking\RankingDeMetasConcluidas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Regra do troféu e do ranking: mais concluídas (total − ativas), depois menos vencidas; empate total divide a posição. */
#[CoversClass(RankingDeMetasConcluidas::class)]
#[Group('dashboard')]
final class RankingDeMetasConcluidasTest extends TestCase
{
    #[TestDox('Concluídas = total menos ativas, nunca negativo; sem meta no total, fora do mapa')]
    public function testConcluidas(): void
    {
        self::assertSame([1 => 3, 2 => 0, 3 => 2], RankingDeMetasConcluidas::concluidas([1 => 5, 2 => 1, 3 => 2], [1 => 2, 2 => 4, 9 => 1]));
    }

    #[TestDox('Mais metas concluídas vem primeiro; quem não tem nenhuma fica no fim')]
    public function testOrdemPorConcluidas(): void
    {
        $posicoes = RankingDeMetasConcluidas::posicoes([1, 2, 3], [2 => 5, 3 => 2], []);

        self::assertSame([2 => 1, 3 => 2, 1 => 3], $posicoes);
        self::assertSame(2, RankingDeMetasConcluidas::campeao([1, 2, 3], [2 => 5, 3 => 2], []));
    }

    #[TestDox('Empate em concluídas: menos vencidas ganha (desempate do desenho)')]
    public function testDesempatePorVencidas(): void
    {
        $concluidas = [1 => 4, 2 => 4];
        $vencidas   = [1 => 3, 2 => 1];

        self::assertSame([2 => 1, 1 => 2], RankingDeMetasConcluidas::posicoes([1, 2], $concluidas, $vencidas));
        self::assertSame(2, RankingDeMetasConcluidas::campeao([1, 2], $concluidas, $vencidas));
    }

    #[TestDox('Empate total: as duas dividem o 1º, o próximo é 3º e ninguém leva o troféu')]
    public function testEmpateTotalSemTrofeu(): void
    {
        $posicoes = RankingDeMetasConcluidas::posicoes([1, 2, 3], [1 => 4, 2 => 4, 3 => 1], [1 => 0, 2 => 0]);

        self::assertSame([1 => 1, 2 => 1, 3 => 3], $posicoes);
        self::assertNull(RankingDeMetasConcluidas::campeao([1, 2, 3], [1 => 4, 2 => 4, 3 => 1], []));
    }

    #[TestDox('Ninguém concluiu: nenhum troféu, mesmo com um só colaborador')]
    public function testSemConcluidaSemTrofeu(): void
    {
        self::assertNull(RankingDeMetasConcluidas::campeao([7], [7 => 0], [7 => 0]));
        self::assertNull(RankingDeMetasConcluidas::campeao([], [], []));
    }
}
