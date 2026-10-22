<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use App\Tarefa\UseCase\ApagarAprendizadoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "Apagar o que foi aprendido" (D4): sai tudo o que é da pessoa naquele escritório, despachos e pendências. */
#[CoversClass(ApagarAprendizadoUseCase::class)]
#[Group('tarefa')]
final class ApagarAprendizadoUseCaseTest extends TestCase
{
    #[TestDox('Apaga em lote os despachos e as pendências da pessoa no escritório, sem carregar entidade por entidade')]
    public function testApagaTudoDaPessoa(): void
    {
        $escritorio = new Tenant();
        $pessoa = new User();
        $despachos = $this->createMock(AprendizadoDoDespachoRepository::class);
        $despachos->expects(self::once())->method('apagarDoUsuario')->with($escritorio, $pessoa)->willReturn(3);
        $despachos->expects(self::never())->method('doUsuario');
        $despachos->expects(self::never())->method('remover');
        $pendencias = $this->createMock(AprendizadoDaPendenciaRepository::class);
        $pendencias->expects(self::once())->method('apagarDoUsuario')->with($escritorio, $pessoa)->willReturn(2);
        $pendencias->expects(self::never())->method('remover');

        self::assertSame(5, (new ApagarAprendizadoUseCase($despachos, $pendencias))->executar($escritorio, $pessoa));
    }
}
