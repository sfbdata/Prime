<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\PassagemPeloEscritorio;
use App\Repository\UserTenantRepository;
use App\Tenant\Repository\SaidaDaEquipeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Quem é ou foi do escritório (E16, decisão 28): o vínculo, ativo ou não; a saída da equipe gravada; ou uma batida
 * gravada nele. Nenhum dos três: não é de lá.
 */
#[CoversClass(PassagemPeloEscritorio::class)]
#[Group('ponto')]
final class PassagemPeloEscritorioTest extends TestCase
{
    #[DataProvider('rastros')]
    #[TestDox('$_dataName')]
    public function testHouve(bool $vinculo, bool $saida, bool $batida, bool $esperado): void
    {
        $pessoa = new User();
        $tenant = new Tenant();
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::once())->method('findPorUserETenant')->with($pessoa, $tenant)
            ->willReturn($vinculo ? $this->createStub(UserTenant::class) : null);
        $saidas = $this->createMock(SaidaDaEquipeRepository::class);
        $saidas->expects($vinculo ? self::never() : self::once())->method('existeDe')->with($pessoa, $tenant)->willReturn($saida);
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects($vinculo || $saida ? self::never() : self::once())->method('existeDoUsuarioNoEscritorio')
            ->with($pessoa, $tenant)->willReturn($batida);

        self::assertSame($esperado, (new PassagemPeloEscritorio($vinculos, $saidas, $registros))->houve($pessoa, $tenant));
    }

    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function rastros(): iterable
    {
        yield 'Com o vínculo (ativo ou não): é do escritório, sem olhar o resto' => [true, false, false, true];
        yield 'Sem o vínculo, com a saída da equipe gravada: foi do escritório, sem olhar as batidas' => [false, true, false, true];
        yield 'Sem o vínculo e sem a saída (saiu antes de 19/10/2026), com batida aqui: foi do escritório' => [false, false, true, true];
        yield 'Sem nenhum dos três: nunca foi do escritório' => [false, false, false, false];
    }
}
