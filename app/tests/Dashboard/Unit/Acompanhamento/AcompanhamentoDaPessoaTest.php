<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Acompanhamento;

use App\Dashboard\Entity\AcompanhamentoDaPessoa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcompanhamentoDaPessoa::class)]
#[Group('dashboard')]
final class AcompanhamentoDaPessoaTest extends TestCase
{
    private function novo(int $prazo = 7, string $agora = '2026-10-27 15:30:00'): AcompanhamentoDaPessoa
    {
        return new AcompanhamentoDaPessoa(new Tenant(), new User(), new User(), new \DateTimeImmutable($agora), $prazo);
    }

    /** @return iterable<string, array{int, string}> */
    public static function prazos(): iterable
    {
        yield '24 horas' => [1, '2026-10-28'];
        yield '48 horas' => [2, '2026-10-29'];
        yield '7 dias' => [7, '2026-11-03'];
    }

    #[DataProvider('prazos')]
    #[TestDox('Prazo de $prazo dia(s): guarda a DATA da revisão, sem hora')]
    public function testGuardaADataDaRevisao(int $prazo, string $esperada): void
    {
        $a = $this->novo($prazo);

        self::assertSame($esperada, $a->getRevisarEm()->format('Y-m-d'));
        self::assertSame('00:00:00', $a->getRevisarEm()->format('H:i:s'));
        self::assertTrue($a->estaAberto());
    }

    #[TestDox('Prazo fora de 1, 2 e 7 é recusado')]
    public function testPrazoInvalido(): void
    {
        $this->expectException(\DomainException::class);

        $this->novo(3);
    }

    #[TestDox('Vencida só depois do dia da revisão: no dia ainda está no prazo; encerrado nunca está vencido')]
    public function testVencimento(): void
    {
        $a = $this->novo(1);

        self::assertFalse($a->estaVencido(new \DateTimeImmutable('2026-10-28 23:59:00')));
        self::assertTrue($a->estaVencido(new \DateTimeImmutable('2026-10-29 00:01:00')));

        $a->encerrar(new User(), new \DateTimeImmutable('2026-10-29 08:00:00'));
        self::assertFalse($a->estaVencido(new \DateTimeImmutable('2026-12-01')));
    }

    #[TestDox('Renovar muda só a data da revisão; encerrar guarda quando e por quem; depois de encerrado não renova nem encerra de novo')]
    public function testRenovarEEncerrar(): void
    {
        $a   = $this->novo(1);
        $por = new User();

        $a->renovar(new \DateTimeImmutable('2026-10-30 10:00:00'), 7);
        self::assertSame('2026-11-06', $a->getRevisarEm()->format('Y-m-d'));

        $a->encerrar($por, new \DateTimeImmutable('2026-10-30 11:00:00'));
        self::assertFalse($a->estaAberto());
        self::assertSame($por, $a->getEncerradoPor());

        $this->expectException(\DomainException::class);
        $a->renovar(new \DateTimeImmutable('2026-10-31'), 1);
    }
}
