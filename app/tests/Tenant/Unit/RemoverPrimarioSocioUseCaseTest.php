<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\RemoverPrimarioSocioInput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\RemoverPrimarioSocioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoverPrimarioSocioUseCase::class)]
#[CoversClass(RegraDosPrimarios::class)]
final class RemoverPrimarioSocioUseCaseTest extends TestCase
{
    private UserTenantRepository&MockObject $userTenantRepository;
    private EntityManagerInterface&MockObject $em;
    private RemoverPrimarioSocioUseCase $useCase;

    protected function setUp(): void
    {
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->em                   = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RemoverPrimarioSocioUseCase(
            $this->userTenantRepository,
            new RegraDosPrimarios($this->userTenantRepository),
            $this->em,
        );
    }

    private function usuario(string $nome): User
    {
        $user = new User();
        $user->setEmail(uniqid('p_') . '@test.com');
        $user->setFullName($nome);
        $user->setIsActive(true);

        return $user;
    }

    private function perfil(Tenant $tenant, bool $sistema): TenantRole
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)');
        $role->setIsSystem($sistema);

        return $role;
    }

    /** @return array{Tenant, User, UserTenant, TenantRole} escritório com titular, o sócio e um perfil secundário */
    private function cenario(): array
    {
        $tenant  = new Tenant();
        $titular = $this->usuario('Titular');
        $tenant->setCriadoPor($titular);
        $socio   = $this->usuario('Sócio');
        $vinculo = (new UserTenant($socio, $tenant))->setTenantRole($this->perfil($tenant, true));

        return [$tenant, $socio, $vinculo, $this->perfil($tenant, false)];
    }

    #[TestDox('remove o sócio: o vínculo passa ao perfil secundário escolhido')]
    public function testRemoveSocio(): void
    {
        [$tenant, $socio, $vinculo, $destino] = $this->cenario();

        $this->userTenantRepository->method('findAtivoPorUserETenant')->with($socio, $tenant)->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(2);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));

        self::assertSame($destino, $vinculo->getTenantRole());
    }

    #[TestDox('o titular (quem abriu a conta) é intocável, mesmo com outros primários')]
    public function testTitularIntocavel(): void
    {
        [$tenant, , , $destino] = $this->cenario();
        $titular = $tenant->getCriadoPor();
        self::assertNotNull($titular);
        $vinculoTitular = (new UserTenant($titular, $tenant))->setTenantRole($this->perfil($tenant, true));

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculoTitular);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(3);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/titular/');

        try {
            $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $titular, $destino));
        } finally {
            self::assertTrue($vinculoTitular->getTenantRole()?->isSystem());
        }
    }

    #[TestDox('nunca deixa o escritório sem primário')]
    public function testUltimoPrimario(): void
    {
        [$tenant, $socio, $vinculo, $destino] = $this->cenario();

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(1);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/último primário/');

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
    }

    #[TestDox('recusa usuário sem vínculo ativo neste escritório (outro escritório não atravessa)')]
    public function testRecusaOutroEscritorio(): void
    {
        [$tenant, $socio, , $destino] = $this->cenario();

        $this->userTenantRepository->method('findAtivoPorUserETenant')->with($socio, $tenant)->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/primário ativo desta conta/');

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
    }

    #[TestDox('recusa vínculo inativo')]
    public function testRecusaVinculoInativo(): void
    {
        [$tenant, $socio, $vinculo, $destino] = $this->cenario();
        $this->desativar($vinculo);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
    }

    #[TestDox('recusa quem não é primário')]
    public function testRecusaQuemNaoEPrimario(): void
    {
        [$tenant, $socio, , $destino] = $this->cenario();
        $vinculo = (new UserTenant($socio, $tenant))->setTenantRole($destino);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/não é primário/');

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
    }

    #[TestDox('exige perfil de destino; recusa destino de sistema ou de outro escritório')]
    public function testDestinoInvalido(): void
    {
        [$tenant, $socio, $vinculo] = $this->cenario();
        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->userTenantRepository->method('contarAdminsAtivos')->willReturn(2);
        $this->em->expects($this->never())->method('flush');

        $destinosInvalidos = [
            'nenhum'           => null,
            'de sistema'       => $this->perfil($tenant, true),
            'outro escritório' => $this->perfil(new Tenant(), false),
        ];

        foreach ($destinosInvalidos as $caso => $destino) {
            try {
                $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
                self::fail('deveria recusar destino: ' . $caso);
            } catch (OperacaoDePrimarioRecusada) {
                self::assertTrue($vinculo->getTenantRole()?->isSystem(), 'o vínculo não pode mudar: ' . $caso);
            }
        }
    }

    #[TestDox('conta desativada não é primário: não pode ser "removida" como sócio')]
    public function testContaDesativadaNaoEPrimario(): void
    {
        [$tenant, $socio, $vinculo, $destino] = $this->cenario();
        $socio->setIsActive(false);

        $this->userTenantRepository->method('findAtivoPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/não é primário/');

        $this->useCase->executar(new RemoverPrimarioSocioInput($tenant, $socio, $destino));
    }

    /** UserTenant não tem desativação pública (o vínculo inativo é legado de antes do hard delete). */
    private function desativar(UserTenant $vinculo): void
    {
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
    }
}
