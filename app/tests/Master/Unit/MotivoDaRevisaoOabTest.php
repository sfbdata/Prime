<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Auth\Enum\StatusOab;
use App\Master\UseCase\ConsultarRevisaoOabUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsultarRevisaoOabUseCase::class)]
final class MotivoDaRevisaoOabTest extends TestCase
{
    /** @return iterable<string, array{StatusOab, string, ?string, ?string}> */
    public static function casos(): iterable
    {
        yield 'não encontrada no CNA (sem nome)' => [StatusOab::Divergente, '81269/DF', null, 'nao_encontrada'];
        yield 'nome ou situação não confere' => [StatusOab::Divergente, '81269/DF', 'Outra Pessoa', 'nome'];
        yield 'formato fora da regra vence' => [StatusOab::Divergente, '12/DF', null, 'formato'];
        yield 'ainda não consultada não tem motivo' => [StatusOab::NaoVerificada, '81269/DF', null, null];
        yield 'confirmada não tem motivo' => [StatusOab::Confirmada, '81269/DF', 'Ana', null];
    }

    #[DataProvider('casos')]
    #[TestDox('motivo da Divergente: $_dataName')]
    public function testMotivo(StatusOab $status, string $inscricao, ?string $nome, ?string $esperado): void
    {
        self::assertSame($esperado, ConsultarRevisaoOabUseCase::motivo($status, $inscricao, $nome));
    }
}
