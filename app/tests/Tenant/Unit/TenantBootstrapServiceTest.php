<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Entity\Marcador;
use App\Service\TenantBootstrapService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantBootstrapService::class)]
final class TenantBootstrapServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TenantBootstrapService $service;
    private Tenant $tenant;
    private User $criador;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new TenantBootstrapService($this->em);
        $this->tenant  = new Tenant();
        $this->criador = (new User())->setEmail('admin@test.com');
        new UserTenant($this->criador, $this->tenant);

        $tenantRoleRepo = $this->createMock(EntityRepository::class);
        $tenantRoleRepo->method('findOneBy')->willReturn(null);

        $permissionRepo = $this->createMock(EntityRepository::class);
        $permissionRepo->method('findAll')->willReturn([]);

        $this->em->method('getRepository')->willReturnMap([
            [TenantRole::class, $tenantRoleRepo],
            [Permission::class, $permissionRepo],
        ]);
    }

    public function testBootstrapCriaTrezeMarcadoresPadrao(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $obj) use (&$persistidos): void {
                $persistidos[] = $obj;
            }
        );

        $this->service->bootstrap($this->tenant, $this->criador);

        $marcadores = array_values(array_filter($persistidos, fn ($o) => $o instanceof Marcador));

        self::assertCount(13, $marcadores);
    }

    public function testBootstrapCriaMarcadoresRaizComNomesOrdemCorretos(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $obj) use (&$persistidos): void {
                $persistidos[] = $obj;
            }
        );

        $this->service->bootstrap($this->tenant, $this->criador);

        $marcadores = array_values(array_filter($persistidos, fn ($o) => $o instanceof Marcador));
        $raizes     = array_values(array_filter($marcadores, fn (Marcador $m) => $m->isRaiz()));
        $nomesRaiz  = array_map(fn (Marcador $m) => $m->getNome(), $raizes);

        self::assertSame(
            ['Nova Pasta', 'Distribuídas', 'Realizadas', 'Protocolar', 'Sem prazo', 'Acervo Geral'],
            $nomesRaiz,
        );

        $ordensRaiz = array_map(fn (Marcador $m) => $m->getOrdem(), $raizes);
        self::assertSame([1, 2, 3, 4, 5, 6], $ordensRaiz);
    }

    public function testBootstrapCriaFilhosDeDistribuidas(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $obj) use (&$persistidos): void {
                $persistidos[] = $obj;
            }
        );

        $this->service->bootstrap($this->tenant, $this->criador);

        $marcadores   = array_values(array_filter($persistidos, fn ($o) => $o instanceof Marcador));
        $distribuidas = array_values(array_filter($marcadores, fn (Marcador $m) => $m->getNome() === 'Distribuídas'));

        self::assertCount(1, $distribuidas);

        $pai        = $distribuidas[0];
        $filhos     = array_values(array_filter($marcadores, fn (Marcador $m) => $m->getPai() === $pai));
        $nomesFilho = array_map(fn (Marcador $m) => $m->getNome(), $filhos);

        self::assertSame(
            ['Advogado 1', 'Advogado 2', 'Advogado 3', 'Advogado 4', 'Setor Administrativo'],
            $nomesFilho,
        );
    }

    public function testBootstrapCriaFilhosDeAcervoGeral(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $obj) use (&$persistidos): void {
                $persistidos[] = $obj;
            }
        );

        $this->service->bootstrap($this->tenant, $this->criador);

        $marcadores = array_values(array_filter($persistidos, fn ($o) => $o instanceof Marcador));
        $acervo     = array_values(array_filter($marcadores, fn (Marcador $m) => $m->getNome() === 'Acervo Geral'));

        self::assertCount(1, $acervo);

        $pai        = $acervo[0];
        $filhos     = array_values(array_filter($marcadores, fn (Marcador $m) => $m->getPai() === $pai));
        $nomesFilho = array_map(fn (Marcador $m) => $m->getNome(), $filhos);

        self::assertSame(['Arquivadas', 'Ativas'], $nomesFilho);
    }

    public function testBootstrapSemCriadorNaoCriaMarcadores(): void
    {
        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $obj) use (&$persistidos): void {
                $persistidos[] = $obj;
            }
        );

        $this->service->bootstrap($this->tenant, null);

        $marcadores = array_filter($persistidos, fn ($o) => $o instanceof Marcador);

        self::assertEmpty($marcadores);
    }
}
