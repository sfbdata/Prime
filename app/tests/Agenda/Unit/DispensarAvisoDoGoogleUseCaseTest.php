<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\UseCase\DispensarAvisoDoGoogleUseCase;
use App\Agenda\UseCase\EstadoDoGoogleAgendaUseCase;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DispensarAvisoDoGoogleUseCase::class)]
final class DispensarAvisoDoGoogleUseCaseTest extends TestCase
{
    #[TestDox('Dispensar grava a chave agenda.google_aviso_dispensado com true, no escritório e no usuário recebidos')]
    public function testDispensarGrava(): void
    {
        $tenant = new Tenant();
        $eu = new User();
        $repo = $this->createMock(PreferenciaDoUsuarioRepository::class);
        $repo->expects(self::once())->method('gravar')->with($tenant, $eu, 'agenda.google_aviso_dispensado', true);

        (new DispensarAvisoDoGoogleUseCase($repo))->executar($tenant, $eu);
    }

    #[TestDox('Mostra a orientação: $caso')]
    #[DataProvider('casos')]
    public function testMostrar(string $caso, string $estado, mixed $gravado, bool $esperado): void
    {
        $repo = $this->createStub(PreferenciaDoUsuarioRepository::class);
        $repo->method('valoresDoUsuario')->willReturn(null === $gravado ? [] : ['agenda.google_aviso_dispensado' => $gravado]);

        self::assertSame($esperado, (new DispensarAvisoDoGoogleUseCase($repo))->mostrar(new Tenant(), new User(), $estado));
    }

    /** @return iterable<string, array{string, string, mixed, bool}> */
    public static function casos(): iterable
    {
        yield 'não conectado e sem dispensa: abre' => ['não conectado e sem dispensa: abre', EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, null, true];
        yield 'não conectado e dispensou: não abre' => ['não conectado e dispensou: não abre', EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, true, false];
        yield 'valor estranho não conta como dispensa' => ['valor estranho não conta como dispensa', EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, 'sim', true];
        yield 'conectado: não abre' => ['conectado: não abre', EstadoDoGoogleAgendaUseCase::CONECTADO, null, false];
        yield 'servidor sem credenciais: não abre' => ['servidor sem credenciais: não abre', EstadoDoGoogleAgendaUseCase::INDISPONIVEL, null, false];
    }
}
