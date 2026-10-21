<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Service\SugestaoDeAtuacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SugestaoDeAtuacao::class)]
#[CoversClass(Atuacao::class)]
final class SugestaoDeAtuacaoTest extends TestCase
{
    /** @return iterable<string, array{?StatusOab, ?VinculoColaborador, ?Atuacao}> */
    public static function casos(): iterable
    {
        yield 'OAB confirmada sugere advogado' => [StatusOab::Confirmada, VinculoColaborador::Clt, Atuacao::Advogado];
        yield 'OAB confirmada vence o estágio' => [StatusOab::Confirmada, VinculoColaborador::Estagio, Atuacao::Advogado];
        yield 'estágio sugere estagiário' => [null, VinculoColaborador::Estagio, Atuacao::Estagiario];
        yield 'OAB não verificada não sugere' => [StatusOab::NaoVerificada, VinculoColaborador::Clt, null];
        yield 'sem escritório e sem OAB' => [null, null, null];
    }

    #[DataProvider('casos')]
    #[TestDox('sugestão: $_dataName')]
    public function testSugestao(?StatusOab $oab, ?VinculoColaborador $vinculo, ?Atuacao $esperado): void
    {
        $user = new User();
        $user->setOabStatus($oab);

        self::assertSame($esperado, SugestaoDeAtuacao::para($user, $vinculo));
    }

    #[TestDox('rótulos do filtro Cargo do desenho 05')]
    public function testRotulos(): void
    {
        self::assertSame(
            ['Advogado(a)', 'Estagiário(a)', 'Controladoria', 'Financeiro', 'Administrativo', 'Outra'],
            array_map(static fn (Atuacao $a): string => $a->rotulo(), Atuacao::cases()),
        );
    }
}
