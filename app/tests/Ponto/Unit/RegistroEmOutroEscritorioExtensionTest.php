<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\RegistroEmOutroEscritorioNoTurno;
use App\Ponto\Twig\RegistroEmOutroEscritorioExtension;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * A guarda da função `dias_com_registro_em_outro_escritorio` (spec `ponto-esquecimento-nao-trava.md` R8): só quem
 * administra o escritório (`admin.users.manage`) e só para pessoa com vínculo ativo nele. Sem isso, vazio, sem consulta.
 */
#[CoversClass(RegistroEmOutroEscritorioExtension::class)]
final class RegistroEmOutroEscritorioExtensionTest extends TestCase
{
    private Tenant $tenant;
    private User $logado;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->logado = new User();
        $this->pessoa = new User();
    }

    #[TestDox('Sem a permissão admin.users.manage no escritório: vazio, e nenhuma batida é lida')]
    public function testSemPermissaoVemVazio(): void
    {
        self::assertSame([], $this->extensao(false, true, false)->dias($this->pessoa, 7, '2026-10'));
    }

    #[TestDox('Pessoa sem vínculo ativo no escritório: vazio, e nenhuma batida é lida')]
    public function testPessoaSemVinculoVemVazio(): void
    {
        self::assertSame([], $this->extensao(true, false, false)->dias($this->pessoa, 7, '2026-10'));
    }

    #[TestDox('Admin do escritório e pessoa com vínculo ativo: a consulta roda')]
    public function testAdminComVinculoConsulta(): void
    {
        self::assertSame([], $this->extensao(true, true, true)->dias($this->pessoa, 7, '2026-10'));
    }

    private function extensao(bool $administra, bool $vinculoAtivo, bool $consulta): RegistroEmOutroEscritorioExtension
    {
        $registros = $this->createMock(RegistroPontoRepository::class);
        $registros->expects($consulta ? self::once() : self::never())->method('horariosEmOutrosEscritorios')->willReturn([]);
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturn($this->tenant);
        $seguranca = $this->createStub(Security::class);
        $seguranca->method('getUser')->willReturn($this->logado);
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->method('canAdminister')->with($this->logado, $this->tenant, 'admin.users.manage')->willReturn($administra);
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->with($this->pessoa, $this->tenant)->willReturn($vinculoAtivo);

        return new RegistroEmOutroEscritorioExtension(
            new RegistroEmOutroEscritorioNoTurno($registros),
            $tenants,
            $seguranca,
            $permissoes,
            $vinculos,
        );
    }
}
