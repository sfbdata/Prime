<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\DTO\PromoverMasterInput;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Service\RegraDosMasters;
use App\Master\UseCase\PromoverMasterUseCase;
use App\Repository\UserRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Promover a Master secundário (spec perfis-1.2-masters M3, M5, §4.2): caminho feliz e cada recusa.
 */
#[CoversClass(PromoverMasterUseCase::class)]
#[CoversClass(RegraDosMasters::class)]
final class PromoverMasterUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private UserTenantRepository&MockObject $userTenantRepository;
    private PromoverMasterUseCase $useCase;
    private User $primario;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->userRepository       = $this->createMock(UserRepository::class);
        $this->userTenantRepository = $this->createMock(UserTenantRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->useCase = new PromoverMasterUseCase($this->userRepository, $this->userTenantRepository, new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)), $this->escopo);
        $this->primario   = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->escritorio = new Tenant();
    }

    #[TestDox('o Master primário promove um secundário ativo do escritório: ganha ROLE_SUPER_ADMIN, mantém os outros papéis e grava')]
    public function testPromoveSecundario(): void
    {
        $alvo = $this->usuario(['ROLE_ALGUM']);
        $this->encontrar($alvo, $this->vinculo($alvo, $this->escritorio, sistema: false));
        $this->userRepository->expects($this->once())->method('salvar')->with($alvo, true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado(), 'grava dentro do escopo de plataforma (tenant_id nulo)'));

        $saida = $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));

        self::assertSame(['ROLE_ALGUM', 'ROLE_SUPER_ADMIN'], RegraDosMasters::papeisGravados($alvo));
        self::assertNotContains('ROLE_MASTER_PRIMARIO', $alvo->getRoles(), 'promovido é secundário, nunca primário');
        self::assertSame('Alvo', $saida->nome);
    }

    #[TestDox('vínculo sem perfil conta como secundário e pode ser promovido')]
    public function testPromoveVinculoSemPerfil(): void
    {
        $alvo = $this->usuario([]);
        $vinculo = new UserTenant($alvo, $this->escritorio);
        $this->encontrar($alvo, $vinculo);
        $this->userRepository->expects($this->once())->method('salvar');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));

        self::assertContains('ROLE_SUPER_ADMIN', $alvo->getRoles());
    }

    #[TestDox('Master secundário (sem ROLE_MASTER_PRIMARIO) é recusado')]
    public function testRecusaMasterSecundario(): void
    {
        $this->esperarRecusa('/Só o Master primário/');

        $this->useCase->executar(new PromoverMasterInput($this->usuario(['ROLE_SUPER_ADMIN']), $this->escritorio, 42));
    }

    #[TestDox('ROLE_MASTER_PRIMARIO sem ROLE_SUPER_ADMIN não vale como primário')]
    public function testRecusaPapelDePrimarioSozinho(): void
    {
        $this->esperarRecusa('/Só o Master primário/');

        $this->useCase->executar(new PromoverMasterInput($this->usuario(['ROLE_MASTER_PRIMARIO']), $this->escritorio, 42));
    }

    #[TestDox('sem escritório na sessão não há de quem promover')]
    public function testRecusaSemEscritorio(): void
    {
        $this->esperarRecusa('/Entre no escritório/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, null, 42));
    }

    #[TestDox('alvo inexistente (ou nenhum escolhido) é recusado')]
    public function testRecusaAlvoInexistente(): void
    {
        $this->userRepository->method('find')->willReturn(null);
        $this->esperarRecusa('/Escolha um usuário cadastrado/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 999));
    }

    #[TestDox('alvo com a conta desativada é recusado')]
    public function testRecusaAlvoInativo(): void
    {
        $alvo = $this->usuario([], ativo: false);
        $this->encontrar($alvo, $this->vinculo($alvo, $this->escritorio, sistema: false));
        $this->esperarRecusa('/desativada/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    #[TestDox('alvo que já é Master é recusado')]
    public function testRecusaJaMaster(): void
    {
        $alvo = $this->usuario(['ROLE_SUPER_ADMIN']);
        $this->encontrar($alvo, $this->vinculo($alvo, $this->escritorio, sistema: false));
        $this->esperarRecusa('/já é Master/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    #[TestDox('alvo sem vínculo ativo NESTE escritório (de outro escritório) é recusado')]
    public function testRecusaAlvoDeOutroEscritorio(): void
    {
        $alvo = $this->usuario([]);
        $this->encontrar($alvo, null);
        $this->esperarRecusa('/usuários ativos desta conta/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    #[TestDox('vínculo devolvido de outro escritório é recusado (o repositório não é a única barreira)')]
    public function testRecusaVinculoDeOutroEscritorioMesmoSeORepositorioDevolver(): void
    {
        $alvo = $this->usuario([]);
        $this->encontrar($alvo, $this->vinculo($alvo, new Tenant(), sistema: false));
        $this->esperarRecusa('/usuários ativos desta conta/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    #[TestDox('vínculo inativo é recusado')]
    public function testRecusaVinculoInativo(): void
    {
        $alvo = $this->usuario([]);
        $vinculo = $this->vinculo($alvo, $this->escritorio, sistema: false);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
        $this->encontrar($alvo, $vinculo);
        $this->esperarRecusa('/usuários ativos desta conta/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    #[TestDox('primário do escritório (perfil de sistema) é promovido como qualquer usuário ativo da conta (decisão do dono, 29/10)')]
    public function testPromovePrimarioDoEscritorio(): void
    {
        $alvo = $this->usuario(['ROLE_USER']);
        $this->encontrar($alvo, $this->vinculo($alvo, $this->escritorio, sistema: true));
        $this->userRepository->expects($this->once())->method('salvar')->with($alvo, true);

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));

        self::assertContains('ROLE_SUPER_ADMIN', $alvo->getRoles());
        self::assertNotContains('ROLE_MASTER_PRIMARIO', $alvo->getRoles(), 'promovido é secundário, nunca primário');
    }

    #[TestDox('primário do escritório sem vínculo ativo com a conta continua recusado')]
    public function testRecusaPrimarioDoEscritorioSemVinculoAtivo(): void
    {
        $alvo = $this->usuario(['ROLE_USER']);
        $this->encontrar($alvo, null);
        $this->esperarRecusa('/Só usuários ativos desta conta/');

        $this->useCase->executar(new PromoverMasterInput($this->primario, $this->escritorio, 42));
    }

    private function esperarRecusa(string $padrao): void
    {
        $this->userRepository->expects($this->never())->method('salvar');
        $this->expectException(OperacaoDeMasterRecusada::class);
        $this->expectExceptionMessageMatches($padrao);
    }

    private function encontrar(User $alvo, ?UserTenant $vinculo): void
    {
        $this->userRepository->method('find')->with(42)->willReturn($alvo);
        $this->userTenantRepository->method('findAtivoPorUserETenant')->with($alvo, $this->escritorio)->willReturn($vinculo);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail(uniqid('master_') . '@test.com');
        $user->setFullName('Alvo');
        $user->setRoles($papeis);
        $user->setIsActive($ativo);

        return $user;
    }

    private function vinculo(User $alvo, Tenant $tenant, bool $sistema): UserTenant
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)')->setIsSystem($sistema);

        return (new UserTenant($alvo, $tenant))->setTenantRole($perfil);
    }
}
