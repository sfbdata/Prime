<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Exception\ColunasDoExpedienteInvalidasException;
use App\Pasta\UseCase\GravarColunasDasDemandasUseCase;
use App\Pasta\UseCase\ObterColunasDasDemandasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Expediente\UseCase\GravarColunasDoExpedienteUseCase;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Colunas das Minhas Demandas por usuário: chave própria (`demandas.colunas`), separada da do Expediente, com a
 * mesma regra de valor.
 */
#[CoversClass(GravarColunasDasDemandasUseCase::class)]
#[CoversClass(ObterColunasDasDemandasUseCase::class)]
final class ColunasDasDemandasUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $user;
    private PreferenciaDoUsuarioRepository&MockObject $preferencias;

    protected function setUp(): void
    {
        $this->tenant       = new Tenant();
        $this->user         = new User();
        $this->preferencias = $this->createMock(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('grava o mapa na chave demandas.colunas (não na do Expediente)')]
    public function testGravaNaChavePropria(): void
    {
        $mapa = ['nup' => true, 'reu' => true, 'sit' => false];
        $this->preferencias->expects(self::once())->method('gravar')->with($this->tenant, $this->user, 'demandas.colunas', $mapa);

        (new GravarColunasDasDemandasUseCase($this->preferencias))->executar($this->tenant, $this->user, $mapa);
    }

    #[TestDox('null apaga só o ajuste das Demandas')]
    public function testNullApaga(): void
    {
        $this->preferencias->expects(self::once())->method('apagarDoUsuario')->with($this->tenant, $this->user, ['demandas.colunas']);
        $this->preferencias->expects(self::never())->method('gravar');

        (new GravarColunasDasDemandasUseCase($this->preferencias))->executar($this->tenant, $this->user, null);
    }

    #[TestDox('a coluna "Mod." vale nas Demandas e continua recusada no Expediente')]
    public function testColunaModSoNasDemandas(): void
    {
        $mapa = ['nup' => true, 'mod' => false];
        $this->preferencias->expects(self::once())->method('gravar')->with($this->tenant, $this->user, 'demandas.colunas', $mapa);

        (new GravarColunasDasDemandasUseCase($this->preferencias))->executar($this->tenant, $this->user, $mapa);

        $this->expectException(ColunasDoExpedienteInvalidasException::class);
        GravarColunasDoExpedienteUseCase::exigirMapaValido($mapa);
    }

    #[TestDox('coluna que a tabela não tem é recusada sem gravar')]
    public function testRecusaColunaDesconhecida(): void
    {
        $this->preferencias->expects(self::never())->method('gravar');
        $this->expectException(ColunasDoExpedienteInvalidasException::class);

        (new GravarColunasDasDemandasUseCase($this->preferencias))->executar($this->tenant, $this->user, ['dono' => true]);
    }

    #[TestDox('a leitura devolve o mapa salvo, e null quando não há ajuste ou o valor é inválido')]
    public function testLeitura(): void
    {
        $this->preferencias->method('valoresDoUsuario')->with($this->tenant, $this->user, ['demandas.colunas'])
            ->willReturnOnConsecutiveCalls(['demandas.colunas' => ['reu' => true]], [], ['demandas.colunas' => 'lixo']);
        $obter = new ObterColunasDasDemandasUseCase($this->preferencias);

        self::assertSame(['reu' => true], $obter->executar($this->tenant, $this->user));
        self::assertNull($obter->executar($this->tenant, $this->user));
        self::assertNull($obter->executar($this->tenant, $this->user));
    }
}
