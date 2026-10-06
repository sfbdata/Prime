<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\ObterPreferenciasDoDashboardUseCase;
use App\Dashboard\UseCase\RestaurarPreferenciasDoDashboardUseCase;
use App\Dashboard\UseCase\SalvarPreferenciaDoDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Gravar, ler e restaurar o estilo do menu ⋮. O repositório é dublê: aqui se prova que o UseCase
 * só deixa passar o que o catálogo aceita, já normalizado, e sempre para o par (escritório,
 * usuário) recebido — a idempotência no banco é provada no teste do repositório.
 */
#[CoversClass(SalvarPreferenciaDoDashboardUseCase::class)]
#[CoversClass(ObterPreferenciasDoDashboardUseCase::class)]
#[CoversClass(RestaurarPreferenciasDoDashboardUseCase::class)]
final class SalvarPreferenciaDoDashboardUseCaseTest extends TestCase
{
    private PreferenciaDoUsuarioRepository&MockObject $repository;
    private Tenant $tenant;
    private User $usuario;

    private const CHAVES = ['dashboard.densidade', 'dashboard.animacoes', 'dashboard.setas', 'dashboard.colunas_ocultas'];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PreferenciaDoUsuarioRepository::class);
        $this->tenant     = new Tenant();
        $this->usuario    = new User();
    }

    #[TestDox('Grava o valor aceito para o escritório e o usuário recebidos e devolve o estilo relido')]
    public function testGravaEDevolveRelido(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('gravar')
            ->with($this->tenant, $this->usuario, 'dashboard.densidade', 'confortavel');
        $this->repository
            ->expects(self::once())
            ->method('valoresDoUsuario')
            ->with($this->tenant, $this->usuario, self::CHAVES)
            ->willReturn(['dashboard.densidade' => 'confortavel']);

        $prefs = (new SalvarPreferenciaDoDashboardUseCase($this->repository))
            ->executar($this->tenant, $this->usuario, 'dashboard.densidade', 'confortavel');

        self::assertSame('confortavel', $prefs->densidade);
        self::assertSame('db-page--confortavel', $prefs->classesCss());
    }

    #[TestDox('Colunas ocultas chegam ao repositório já normalizadas (sem repetição, ordem da tabela)')]
    public function testGravaNormalizado(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('gravar')
            ->with($this->tenant, $this->usuario, 'dashboard.colunas_ocultas', ['cargo', 'pastas_criadas']);
        $this->repository->method('valoresDoUsuario')->willReturn([]);

        (new SalvarPreferenciaDoDashboardUseCase($this->repository))
            ->executar($this->tenant, $this->usuario, 'dashboard.colunas_ocultas', ['pastas_criadas', 'cargo', 'cargo']);
    }

    #[TestDox('Chave fora da lista é recusada e NADA é gravado')]
    public function testChaveForaDaListaNaoGrava(): void
    {
        $this->repository->expects(self::never())->method('gravar');

        $this->expectException(PreferenciaInvalidaException::class);

        (new SalvarPreferenciaDoDashboardUseCase($this->repository))
            ->executar($this->tenant, $this->usuario, 'dashboard.sons', true);
    }

    #[TestDox('Valor fora da lista é recusado e NADA é gravado')]
    public function testValorForaDaListaNaoGrava(): void
    {
        $this->repository->expects(self::never())->method('gravar');

        $this->expectException(PreferenciaInvalidaException::class);

        (new SalvarPreferenciaDoDashboardUseCase($this->repository))
            ->executar($this->tenant, $this->usuario, 'dashboard.animacoes', '<script>');
    }

    #[TestDox('Ler devolve o padrão completo para o que não foi gravado')]
    public function testObterCompletaComPadrao(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('valoresDoUsuario')
            ->with($this->tenant, $this->usuario, self::CHAVES)
            ->willReturn(['dashboard.setas' => 'desligadas']);

        $prefs = (new ObterPreferenciasDoDashboardUseCase($this->repository))->executar($this->tenant, $this->usuario);

        self::assertSame('compacta', $prefs->densidade);
        self::assertSame('desligadas', $prefs->setas);
        self::assertSame('db-page--sem-setas', $prefs->classesCss());
    }

    #[TestDox('Restaurar padrão apaga só as chaves do catálogo do par (escritório, usuário) e devolve o padrão')]
    public function testRestaurarApagaDoUsuario(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('apagarDoUsuario')
            ->with($this->tenant, $this->usuario, self::CHAVES)
            ->willReturn(2);
        $this->repository->expects(self::never())->method('gravar');

        $prefs = (new RestaurarPreferenciasDoDashboardUseCase($this->repository))->executar($this->tenant, $this->usuario);

        self::assertSame('', $prefs->classesCss());
    }
}
