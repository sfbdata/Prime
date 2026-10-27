<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\UseCase\BuscarPastasParaTarefaDoAlertaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuscarPastasParaTarefaDoAlertaUseCase::class)]
#[Group('dashboard')]
final class BuscarPastasParaTarefaDoAlertaUseCaseTest extends TestCase
{
    #[TestDox('Busca no escritório da sessão, COM a pessoa (cadeado), com limite e termo aparado')]
    public function testBuscaComCadeadoELimite(): void
    {
        $tenant  = new Tenant();
        $usuario = new User();
        $esperado = [['id' => 5, 'texto' => 'Pasta 5']];

        $repo = $this->createMock(PastaRepository::class);
        $repo->expects(self::once())->method('buscarParaVinculo')
            ->with('silva', $tenant, BuscarPastasParaTarefaDoAlertaUseCase::LIMITE, [], $usuario)
            ->willReturn($esperado);

        self::assertSame($esperado, (new BuscarPastasParaTarefaDoAlertaUseCase($repo))->executar($tenant, $usuario, '  silva  '));
    }

    #[TestDox('Termo enorme é cortado em 100 caracteres')]
    public function testTermoCortado(): void
    {
        $repo = $this->createMock(PastaRepository::class);
        $repo->expects(self::once())->method('buscarParaVinculo')
            ->with(str_repeat('a', 100), self::anything(), self::anything(), [], self::anything())
            ->willReturn([]);

        (new BuscarPastasParaTarefaDoAlertaUseCase($repo))->executar(new Tenant(), new User(), str_repeat('a', 300));
    }
}
