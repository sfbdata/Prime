<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\DTO\MarcarAlertaDaInteligenciaInput;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\MarcasDosAlertas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\MarcarAlertaDaInteligenciaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarAlertaDaInteligenciaUseCase::class)]
#[Group('dashboard')]
final class MarcarAlertaDaInteligenciaUseCaseTest extends TestCase
{
    private PreferenciaDoUsuarioRepository&MockObject $repo;
    private MarcarAlertaDaInteligenciaUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(PreferenciaDoUsuarioRepository::class);
        $this->sut     = new MarcarAlertaDaInteligenciaUseCase($this->repo);
        $this->tenant  = new Tenant();
        $this->usuario = new User();
    }

    #[TestDox('Grava a marca para o usuário e o escritório da sessão e devolve o que a tela mostra')]
    public function testGravaMarca(): void
    {
        $this->repo->method('valoresDoUsuario')->with($this->tenant, $this->usuario, [MarcasDosAlertas::CHAVE])->willReturn([]);
        $this->repo->expects($this->once())->method('gravar')->with(
            $this->tenant,
            $this->usuario,
            MarcasDosAlertas::CHAVE,
            ['vencidas' => ['status' => 'resolvido', 'feedback' => null]],
        );

        $out = $this->sut->executar(new MarcarAlertaDaInteligenciaInput($this->tenant, $this->usuario, 'vencidas', 'resolvido'));

        self::assertSame(['alerta' => 'vencidas', 'status' => 'resolvido', 'feedback' => null], $out->paraArray());
    }

    #[TestDox('Repetir a marca a desfaz e grava o mapa sem ela')]
    public function testDesfaz(): void
    {
        $this->repo->method('valoresDoUsuario')->willReturn([MarcasDosAlertas::CHAVE => ['prazos' => ['status' => 'ignorado', 'feedback' => null]]]);
        $this->repo->expects($this->once())->method('gravar')->with($this->tenant, $this->usuario, MarcasDosAlertas::CHAVE, []);

        $out = $this->sut->executar(new MarcarAlertaDaInteligenciaInput($this->tenant, $this->usuario, 'prazos', 'ignorado'));

        self::assertNull($out->status);
    }

    #[TestDox('Preserva as marcas dos outros alertas do mesmo usuário')]
    public function testPreservaOutras(): void
    {
        $this->repo->method('valoresDoUsuario')->willReturn([MarcasDosAlertas::CHAVE => ['prazos' => ['status' => 'ignorado', 'feedback' => null]]]);
        $this->repo->expects($this->once())->method('gravar')->with(
            $this->anything(),
            $this->anything(),
            MarcasDosAlertas::CHAVE,
            [
                'prazos'   => ['status' => 'ignorado', 'feedback' => null],
                'vencidas' => ['status' => null, 'feedback' => 'nao'],
            ],
        );

        $out = $this->sut->executar(new MarcarAlertaDaInteligenciaInput($this->tenant, $this->usuario, 'vencidas', 'nao'));

        self::assertSame('nao', $out->feedback);
    }

    #[TestDox('Alerta fora da Central: recusa e não grava nada')]
    public function testRecusaAlertaDesconhecido(): void
    {
        $this->repo->method('valoresDoUsuario')->willReturn([]);
        $this->repo->expects($this->never())->method('gravar');

        $this->expectException(PreferenciaInvalidaException::class);
        $this->sut->executar(new MarcarAlertaDaInteligenciaInput($this->tenant, $this->usuario, 'qualquer', 'resolvido'));
    }
}
