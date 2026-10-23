<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\TurnoAbertoNoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O turno aberto neste escritório, com a janela de 16 horas do turno noturno (spec seguranca-b-jornada §3.3). */
#[CoversClass(TurnoAbertoNoEscritorio::class)]
final class TurnoAbertoNoEscritorioTest extends TestCase
{
    #[TestDox('Turno noturno: às 06:00 a busca começa 16 horas antes, às 14:00 de ontem, e acha a entrada das 22:00')]
    public function testTurnoNoturnoAberto(): void
    {
        $agora = new \DateTimeImmutable('2026-10-23 06:00:00');
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects(self::once())->method('ultimaBatidaNoTenant')
            ->with(self::anything(), self::anything(), new \DateTimeImmutable('2026-10-22 14:00:00'), $agora)
            ->willReturn($this->batida(RegistroPonto::TIPO_ENTRADA, '2026-10-22 22:00:00'));

        self::assertTrue((new TurnoAbertoNoEscritorio($registros))->estaAberto(new User(), new Tenant(), $agora));
    }

    #[DataProvider('ultimas')]
    #[TestDox('Aberto só quando a última batida da janela é entrada ou retorno')]
    public function testUltimaBatida(?string $tipo, bool $aberto): void
    {
        $registros = $this->createStub(RegistroPontoRepository::class);
        $registros->method('ultimaBatidaNoTenant')->willReturn($tipo === null ? null : $this->batida($tipo, '2026-10-23 08:00:00'));

        self::assertSame($aberto, (new TurnoAbertoNoEscritorio($registros))->estaAberto(new User(), new Tenant(), new \DateTimeImmutable('2026-10-23 09:00:00')));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function ultimas(): iterable
    {
        yield 'entrada' => [RegistroPonto::TIPO_ENTRADA, true];
        yield 'retorno' => [RegistroPonto::TIPO_RETORNO, true];
        yield 'repouso' => [RegistroPonto::TIPO_REPOUSO, false];
        yield 'saída' => [RegistroPonto::TIPO_SAIDA, false];
        yield 'nenhuma na janela' => [null, false];
    }

    private function batida(string $tipo, string $quando): RegistroPonto
    {
        $batida = new RegistroPonto();
        $batida->setTipo($tipo);
        $batida->setDataHora(new \DateTime($quando));

        return $batida;
    }
}
