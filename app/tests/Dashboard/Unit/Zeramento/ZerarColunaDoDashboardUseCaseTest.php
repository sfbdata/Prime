<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Dashboard\DTO\ZerarColunaInput;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\ColunaNaoZeravelException;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\UseCase\ZerarColunaDoDashboardUseCase;
use App\Dashboard\Zeramento\ColaboradorDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** "Zerar" (DASH-014): grava o corte e quem zerou; nada se apaga. Só o Master ou um primário ativo. */
#[CoversClass(ZerarColunaDoDashboardUseCase::class)]
final class ZerarColunaDoDashboardUseCaseTest extends TestCase
{
    private DashboardZeramentoRepository&MockObject $repo;
    private UserTenantRepository&MockObject $vinculos;
    private Tenant $tenant;
    private User $colaborador;
    private ZerarColunaDoDashboardUseCase $useCase;

    protected function setUp(): void
    {
        $this->repo     = $this->createMock(DashboardZeramentoRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->tenant   = new Tenant();
        $this->colaborador = PessoaDeTeste::criar(7);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn([$this->colaborador]);

        $this->useCase = new ZerarColunaDoDashboardUseCase(
            $this->repo,
            new RegraDosPrimarios($this->vinculos),
            new ColaboradorDoEscritorio($usuarios),
        );
    }

    private function comoPrimario(User $autor, bool $primario): void
    {
        $papel = (new TenantRole())->setIsSystem($primario);
        $vinculo = (new UserTenant($autor, $this->tenant))->setTenantRole($papel);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);
    }

    #[TestDox('O primário zera a coluna de uma pessoa: o corte é agora, com o autor, e a gravação vai ao banco')]
    public function testPrimarioZeraDeUmaPessoa(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $agora = new \DateTimeImmutable('2026-10-23 14:05:00');
        $gravado = null;
        $this->repo->expects($this->once())->method('salvar')->with(
            $this->callback(static function (DashboardZeramento $z) use (&$gravado): bool {
                $gravado = $z;

                return true;
            }),
            true,
        );

        $saida = $this->useCase->executar(new ZerarColunaInput($this->tenant, $autor, 'metas', 7, $agora));

        self::assertSame('metas', $saida->coluna);
        self::assertSame(7, $saida->pessoaId);
        self::assertSame($agora, $gravado?->getContarDesde());
        self::assertSame($autor, $gravado?->getPor());
        self::assertSame($this->colaborador, $gravado?->getUsuario());
        self::assertSame($this->tenant, $gravado?->getTenant());
    }

    #[TestDox('O Master zera a equipe toda (sem pessoa), mesmo sem vínculo de primário')]
    public function testMasterZeraAEquipe(): void
    {
        $master = PessoaDeTeste::criar(2, ['ROLE_SUPER_ADMIN']);
        $this->repo->expects($this->once())->method('salvar');

        $saida = $this->useCase->executar(new ZerarColunaInput($this->tenant, $master, 'relatorio', null, new \DateTimeImmutable()));

        self::assertNull($saida->pessoaId);
    }

    #[TestDox('Quem não é primário é recusado e nada é gravado')]
    public function testNaoPrimarioRecusado(): void
    {
        $autor = PessoaDeTeste::criar(3);
        $this->comoPrimario($autor, false);
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(ZeramentoNaoPermitidoException::class);
        $this->useCase->executar(new ZerarColunaInput($this->tenant, $autor, 'metas', null, new \DateTimeImmutable()));
    }

    #[TestDox('Coluna fora da tabela é recusada')]
    public function testColunaInvalida(): void
    {
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(ColunaNaoZeravelException::class);
        $this->useCase->executar(new ZerarColunaInput($this->tenant, PessoaDeTeste::criar(2, ['ROLE_SUPER_ADMIN']), 'senha', null, new \DateTimeImmutable()));
    }

    #[TestDox('Pessoa que não é colaboradora ativa do escritório da sessão é recusada (IDOR)')]
    public function testPessoaDeOutroEscritorio(): void
    {
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $this->useCase->executar(new ZerarColunaInput($this->tenant, PessoaDeTeste::criar(2, ['ROLE_SUPER_ADMIN']), 'metas', 99, new \DateTimeImmutable()));
    }
}
