<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\InicioContagemResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InicioContagemResolver::class)]
final class InicioContagemResolverTest extends TestCase
{
    public function testUsaAPrimeiraBatidaQuandoNaoHaAbono(): void
    {
        $resolver = $this->resolver('2026-05-18', null);

        self::assertSame('2026-05-18', $resolver->resolver(new User(), new Tenant())?->format('Y-m-d'));
    }

    public function testUsaOPrimeiroAbonoQuandoNaoHaBatida(): void
    {
        // Colaborador que só tem abono deferido (ainda não bateu ponto) já tem contagem aberta.
        $resolver = $this->resolver(null, '2026-05-04');

        self::assertSame('2026-05-04', $resolver->resolver(new User(), new Tenant())?->format('Y-m-d'));
    }

    public function testAbonoAnteriorAPrimeiraBatidaAbreAContagem(): void
    {
        // O caso que motivou a regra: esqueceu de bater os primeiros dias e o admin deferiu abono.
        $resolver = $this->resolver('2026-05-18', '2026-05-15');

        self::assertSame('2026-05-15', $resolver->resolver(new User(), new Tenant())?->format('Y-m-d'));
    }

    public function testBatidaAnteriorAoAbonoMandaNaContagem(): void
    {
        $resolver = $this->resolver('2026-05-10', '2026-06-02');

        self::assertSame('2026-05-10', $resolver->resolver(new User(), new Tenant())?->format('Y-m-d'));
    }

    public function testSemNenhumRegistroRetornaNull(): void
    {
        // null = não há o que contar (a folha não gera débito fantasma).
        $resolver = $this->resolver(null, null);

        self::assertNull($resolver->resolver(new User(), new Tenant()));
    }

    private function resolver(?string $primeiraBatida, ?string $primeiroAbono): InicioContagemResolver
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->method('findDataPrimeiraBatida')
            ->willReturn($primeiraBatida === null ? null : new \DateTimeImmutable($primeiraBatida));

        $justificativas = $this->createMock(JustificativaPontoRepository::class);
        $justificativas->method('findDataPrimeiraAbonada')
            ->willReturn($primeiroAbono === null ? null : new \DateTimeImmutable($primeiroAbono));

        return new InicioContagemResolver($registros, $justificativas);
    }
}
