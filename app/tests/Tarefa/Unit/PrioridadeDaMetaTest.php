<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrioridadeDaMeta::class)]
final class PrioridadeDaMetaTest extends TestCase
{
    /** @return iterable<string, array{PrioridadePasta, PrioridadeDaMeta}> */
    public static function heranca(): iterable
    {
        yield 'pasta urgente' => [PrioridadePasta::Urgente, PrioridadeDaMeta::Urgente];
        yield 'pasta prioridade' => [PrioridadePasta::Prioridade, PrioridadeDaMeta::Alta];
        yield 'pasta normal' => [PrioridadePasta::Normal, PrioridadeDaMeta::Normal];
    }

    #[DataProvider('heranca')]
    #[TestDox('Herança da pasta: $_dataName')]
    public function testDaPasta(PrioridadePasta $daPasta, PrioridadeDaMeta $esperada): void
    {
        self::assertSame($esperada, PrioridadeDaMeta::daPasta($daPasta));
    }

    #[TestDox('Rótulo é a chave do catálogo, uma por valor (Normal, Alta, Urgente)')]
    public function testRotuloEhChaveDoCatalogo(): void
    {
        self::assertSame(
            ['metas.prioridade.normal', 'metas.prioridade.alta', 'metas.prioridade.urgente'],
            array_map(static fn (PrioridadeDaMeta $p): string => $p->rotulo(), PrioridadeDaMeta::cases()),
        );
    }
}
