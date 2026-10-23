<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Dashboard\DTO\DesfazerZeramentosInput;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Exception\ZeramentoNaoPermitidoException;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\UseCase\DesfazerZeramentosDoDashboardUseCase;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** "Desfazer zeramentos" (DASH-014): marca desfeito_em/desfeito_por nos ativos DA coluna e a contagem volta. */
#[CoversClass(DesfazerZeramentosDoDashboardUseCase::class)]
final class DesfazerZeramentosDoDashboardUseCaseTest extends TestCase
{
    private DashboardZeramentoRepository&MockObject $repo;
    private UserTenantRepository&MockObject $vinculos;
    private Tenant $tenant;
    private DesfazerZeramentosDoDashboardUseCase $useCase;

    protected function setUp(): void
    {
        $this->repo     = $this->createMock(DashboardZeramentoRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->tenant   = new Tenant();
        $this->useCase  = new DesfazerZeramentosDoDashboardUseCase($this->repo, new RegraDosPrimarios($this->vinculos));
    }

    private function zeramento(string $coluna): DashboardZeramento
    {
        return new DashboardZeramento($this->tenant, $coluna, null, new \DateTimeImmutable('2026-10-20 10:00'), PessoaDeTeste::criar(1));
    }

    #[TestDox('Desfaz só os ativos da coluna pedida, com quem desfez e quando, e grava')]
    public function testDesfazOsDaColuna(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $papel = (new TenantRole())->setIsSystem(true);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn((new UserTenant($autor, $this->tenant))->setTenantRole($papel));
        $metasEquipe = $this->zeramento('metas');
        $metasPessoa = $this->zeramento('metas');
        $prazos      = $this->zeramento('prazos');
        $this->repo->method('listarAtivos')->with($this->tenant)->willReturn([$metasEquipe, $prazos, $metasPessoa]);
        $this->repo->expects($this->once())->method('salvar')->with($this->anything(), true);
        $agora = new \DateTimeImmutable('2026-10-23 09:00');

        self::assertSame(2, $this->useCase->executar(new DesfazerZeramentosInput($this->tenant, $autor, 'metas', $agora)));
        self::assertFalse($metasEquipe->estaAtivo());
        self::assertFalse($metasPessoa->estaAtivo());
        self::assertSame($autor, $metasEquipe->getDesfeitoPor());
        self::assertSame($agora, $metasPessoa->getDesfeitoEm());
        self::assertTrue($prazos->estaAtivo());
    }

    #[TestDox('Sem zeramento ativo na coluna, nada a gravar e volta zero')]
    public function testNadaADesfazer(): void
    {
        $this->repo->method('listarAtivos')->willReturn([$this->zeramento('prazos')]);
        $this->repo->expects($this->never())->method('salvar');

        $master = PessoaDeTeste::criar(2, ['ROLE_SUPER_ADMIN']);
        self::assertSame(0, $this->useCase->executar(new DesfazerZeramentosInput($this->tenant, $master, 'metas', new \DateTimeImmutable())));
    }

    #[TestDox('Quem não é primário é recusado e nada é desfeito')]
    public function testNaoPrimarioRecusado(): void
    {
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn(null);
        $ativo = $this->zeramento('metas');
        $this->repo->method('listarAtivos')->willReturn([$ativo]);
        $this->repo->expects($this->never())->method('salvar');

        try {
            $this->useCase->executar(new DesfazerZeramentosInput($this->tenant, PessoaDeTeste::criar(3), 'metas', new \DateTimeImmutable()));
            self::fail('Deveria recusar.');
        } catch (ZeramentoNaoPermitidoException) {
            self::assertTrue($ativo->estaAtivo());
        }
    }
}
