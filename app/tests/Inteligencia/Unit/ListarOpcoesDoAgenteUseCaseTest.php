<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Inteligencia\UseCase\ListarOpcoesDoAgenteUseCase;
use App\Repository\UserRepository;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O vocabulário do agente vem das mesmas fontes da Lista, escopadas pelo escritório, e os valores de
 * situação e prioridade são exatamente os que o filtro aceita.
 */
#[CoversClass(ListarOpcoesDoAgenteUseCase::class)]
final class ListarOpcoesDoAgenteUseCaseTest extends TestCase
{
    #[TestDox('Responsáveis e marcadores do escritório; situações e prioridades válidas')]
    public function testOpcoes(): void
    {
        $tenant = new Tenant();
        $mariana = new User();
        $mariana->setFullName('Mariana');
        DefineId::em($mariana, 3);
        $usuarios = $this->createMock(UserRepository::class);
        $usuarios->expects(self::once())->method('findColaboradoresAtivosPorTenant')->with($tenant)->willReturn([$mariana]);
        $marcadores = $this->createMock(MarcadorRepository::class);
        $marcadores->expects(self::once())->method('findTodosPorTenant')->with($tenant)->willReturn([]);

        $saida = (new ListarOpcoesDoAgenteUseCase($usuarios, $marcadores))->executar($tenant);

        self::assertSame([['id' => 3, 'nome' => 'Mariana']], $saida->responsaveis);
        self::assertSame([], $saida->marcadores);
        self::assertSame(['ativo', 'arquivado'], array_column($saida->situacoes, 'valor'));
        self::assertSame(['normal', 'prioridade', 'urgente'], array_column($saida->prioridades, 'valor'));
    }
}
