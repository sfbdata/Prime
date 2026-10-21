<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\Cadeado;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Peças em memória para os testes unitários do cadeado: escritório, pessoas com vínculo, um repositório de
 * cadeados em memória e os serviços REAIS (`NivelNoCadeado`, `AcessoAPasta`, `RegraDosPrimarios`), que são
 * finais e montados sobre os repositórios dublês.
 */
trait CriaCenarioDoCadeadoTrait
{
    private Tenant $tenant;

    /** @var array<int, UserTenant> vínculos ativos por id de usuário */
    private array $vinculos = [];

    /** @var array<int, CadeadoDaPasta> cadeados em memória por id de pasta */
    private array $cadeadosGravados = [];

    private CadeadoDaPastaRepository&MockObject $cadeadoRepository;

    private UserTenantRepository&MockObject $userTenantRepository;

    private PermissionChecker&MockObject $permissionChecker;

    private int $proximoId = 100;

    private function montarCenario(): void
    {
        $this->tenant = $this->comId(new Tenant());
        $this->tenant->setName('Escritório do cadeado');

        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->userTenantRepository->method('findAtivoPorUserETenant')
            ->willReturnCallback(fn (User $u, Tenant $t): ?UserTenant => $t === $this->tenant ? ($this->vinculos[$u->getId()] ?? null) : null);
        $this->userTenantRepository->method('findAtivosComPerfilPorTenant')
            ->willReturnCallback(fn (): array => array_values($this->vinculos));

        $this->cadeadoRepository = $this->createMock(CadeadoDaPastaRepository::class);
        $this->cadeadoRepository->method('daPastaPorId')->willReturnCallback(fn (int $id): ?CadeadoDaPasta => $this->cadeadosGravados[$id] ?? null);
        $this->cadeadoRepository->method('daPasta')->willReturnCallback(fn (Pasta $p): ?CadeadoDaPasta => $this->cadeadosGravados[$p->getId()] ?? null);
        $this->cadeadoRepository->method('salvar')->willReturnCallback(function (CadeadoDaPasta $c): void {
            $this->cadeadosGravados[(int) $c->getPasta()->getId()] = $c;
        });
        $this->cadeadoRepository->method('remover')->willReturnCallback(function (CadeadoDaPasta $c): void {
            unset($this->cadeadosGravados[(int) $c->getPasta()->getId()]);
        });

        $this->permissionChecker = $this->createMock(PermissionChecker::class);
    }

    /** @template T of object @param T $objeto @return T */
    private function comId(object $objeto): object
    {
        (new \ReflectionProperty($objeto, 'id'))->setValue($objeto, ++$this->proximoId);

        return $objeto;
    }

    private function pessoa(string $nome, bool $primario = false, bool $master = false, bool $comVinculo = true): User
    {
        $user = $this->comId(new User());
        $user->setFullName($nome);
        $user->setEmail(strtolower(str_replace(' ', '.', $nome)) . '@cadeado.test');
        if ($master) {
            $user->setRoles(['ROLE_SUPER_ADMIN']);
        }
        if ($comVinculo) {
            $perfil = new TenantRole();
            $perfil->setTenant($this->tenant);
            $perfil->setName($primario ? 'Administrador do Escritório' : 'Advogado (a)');
            $perfil->setIsSystem($primario);
            $this->vinculos[(int) $user->getId()] = (new UserTenant($user, $this->tenant))->setTenantRole($perfil);
        }

        return $user;
    }

    private function pasta(string $nup = '1500'): Pasta
    {
        $pasta = $this->comId(new Pasta());
        $pasta->setNup($nup);
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    private function gravarCadeado(CadeadoDaPasta $cadeado): CadeadoDaPasta
    {
        $this->cadeadosGravados[(int) $cadeado->getPasta()->getId()] = $cadeado;

        return $cadeado;
    }

    private function regraDosPrimarios(): RegraDosPrimarios
    {
        return new RegraDosPrimarios($this->userTenantRepository);
    }

    private function nivelNoCadeado(): NivelNoCadeado
    {
        $tenantContext = new TenantContext(new RequestStack(), $this->createStub(Security::class), $this->createStub(EntityManagerInterface::class));

        return new NivelNoCadeado($this->cadeadoRepository, $this->userTenantRepository, $this->regraDosPrimarios(), $tenantContext);
    }

    private function acessoAPasta(): AcessoAPasta
    {
        return new AcessoAPasta($this->nivelNoCadeado(), $this->permissionChecker);
    }
}
