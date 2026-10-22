<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
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
    #[TestDox('Remove os despachos e as pendências da pessoa no escritório e confirma uma vez')]
    public function testApagaTudoDaPessoa(): void
    {
        $escritorio = new Tenant();
        $pessoa = new User();
        $despacho = new AprendizadoDoDespacho($escritorio, $pessoa, EtapaDaPasta::Juridico, null, 'Texto', 0);
        $pendencia = new AprendizadoDaPendencia($escritorio, $pessoa, EtapaDaPasta::Juridico, 'RG');
        $despachos = $this->createMock(AprendizadoDoDespachoRepository::class);
        $despachos->expects(self::once())->method('doUsuario')->with($escritorio, $pessoa)->willReturn([$despacho]);
        $despachos->expects(self::once())->method('remover')->with($despacho);
        $despachos->expects(self::once())->method('confirmar');
        $pendencias = $this->createMock(AprendizadoDaPendenciaRepository::class);
        $pendencias->expects(self::once())->method('doUsuario')->with($escritorio, $pessoa)->willReturn([$pendencia]);
        $pendencias->expects(self::once())->method('remover')->with($pendencia);

        self::assertSame(2, (new ApagarAprendizadoUseCase($despachos, $pendencias))->executar($escritorio, $pessoa));
    }
}
