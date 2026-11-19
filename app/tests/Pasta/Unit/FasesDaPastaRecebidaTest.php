<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\FasesDaPastaRecebida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As fases ao alcance de quem recebeu a pasta de outra conta (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b):
 * o mesmo lado do Contrato, sem Finalizar nem Reativar.
 */
#[CoversClass(FasesDaPastaRecebida::class)]
final class FasesDaPastaRecebidaTest extends TestCase
{
    /** @return iterable<string, array{0: int, 1: list<int>, 2?: list<array{0: string, 1: string}>}> */
    public static function casos(): iterable
    {
        yield 'Abertura: só Documentação' => [0, [1]];
        yield 'Documentação: só Abertura' => [1, [0]];
        yield 'no Contrato: nada (sair dele é cruzar)' => [2, []];
        yield 'Distribuído: de Protocolo a Cumprimento, sem Finalizada' => [3, [4, 5, 6, 7, 8]];
        yield 'Cumprimento: de Distribuído a Sentença' => [8, [3, 4, 5, 6, 7]];
        yield 'Finalizada: nada (sair ou reativar mexe em "Pastas concluídas")' => [9, []];
        yield 'reativada, em andamento: sem as duas Finalizadas nem a Reativada' => [11, [3, 4, 5, 6, 7, 8], [['Reativada', 'bi-arrow-repeat'], ['Em andamento', 'bi-hourglass-split'], ['Finalizada', 'bi-flag']]];
    }

    /**
     * @param list<int>                         $esperadas
     * @param list<array{0: string, 1: string}> $extra
     */
    #[DataProvider('casos')]
    #[TestDox('$_dataName')]
    public function testAoAlcance(int $atual, array $esperadas, array $extra = []): void
    {
        $estado = ['fases' => AndamentoDaPasta::fases(PastaAndamento::VIA_JUDICIAL, $extra), 'atual' => $atual];

        self::assertSame($esperadas, FasesDaPastaRecebida::aoAlcance($estado));
    }

    #[TestDox('A trilha administrativa segue a mesma regra (o Contrato também é a terceira fase)')]
    public function testTrilhaAdministrativa(): void
    {
        $estado = ['fases' => AndamentoDaPasta::fases(PastaAndamento::VIA_ADMINISTRATIVA, []), 'atual' => 4];

        self::assertSame([3, 5, 6, 7, 8], FasesDaPastaRecebida::aoAlcance($estado));
    }
}
