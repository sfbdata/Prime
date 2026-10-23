<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Tests\Ponto\Support\RegistroDePontoInicialDeTesteTrait;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Exception\MembroDaEquipeNaoEncontrado;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\AlterarSituacaoNaEquipeUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarSituacaoNaEquipeUseCase::class)]
#[CoversClass(SituacaoNaEquipe::class)]
#[CoversClass(UserTenant::class)]
final class AlterarSituacaoNaEquipeUseCaseTest extends TestCase
{
    use RegistroDePontoInicialDeTesteTrait;

    private Tenant $tenant;
    private User $primario;
    private UserTenant $vinculoPrimario;

    protected function setUp(): void
    {
        $this->tenant          = $this->comId(new Tenant(), 1);
        $this->primario        = $this->usuario(10);
        $this->vinculoPrimario = $this->vinculo($this->primario, true, 100);
    }

    #[TestDox('suspender desativa o vínculo; aprovar de novo reativa; a mesma situação não grava de novo')]
    public function testSuspenderEAprovar(): void
    {
        $alvo  = $this->vinculo($this->usuario(11), false, 101);
        $repo  = $this->equipe([101 => $alvo]);
        $repo->expects(self::exactly(2))->method('gravar');
        $sut   = new AlterarSituacaoNaEquipeUseCase($repo, $this->regra(), $this->registroDePontoInicialSemChoque());

        $sut->executar($this->primario, $this->tenant, 101, SituacaoNaEquipe::Suspenso);
        self::assertSame([SituacaoNaEquipe::Suspenso, false], [$alvo->getSituacao(), $alvo->isActive()]);

        $sut->executar($this->primario, $this->tenant, 101, SituacaoNaEquipe::Suspenso);
        $sut->executar($this->primario, $this->tenant, 101, SituacaoNaEquipe::Aprovado);
        self::assertSame([SituacaoNaEquipe::Aprovado, true], [$alvo->getSituacao(), $alvo->isActive()]);
    }

    #[TestDox('recusa: quem não é primário nem Master, o próprio primário, um Master e vínculo de outro escritório')]
    public function testRecusas(): void
    {
        $secundario = $this->usuario(11);
        $master     = $this->usuario(12, ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $alvos      = [101 => $this->vinculo($secundario, false, 101), 102 => $this->vinculo($master, false, 102), 100 => $this->vinculoPrimario];
        $sut        = new AlterarSituacaoNaEquipeUseCase($this->equipe($alvos), $this->regra([11 => $alvos[101]]), $this->registroDePontoInicialSemChoque());

        foreach ([
            [$secundario, 102, 'Somente o usuário primário'],
            [$this->primario, 100, 'própria situação'],
            [$this->primario, 102, 'Master não pode'],
        ] as [$ator, $id, $msg]) {
            try {
                $sut->executar($ator, $this->tenant, $id, SituacaoNaEquipe::Suspenso);
                self::fail('devia recusar: ' . $msg);
            } catch (OperacaoDePrimarioRecusada $e) {
                self::assertStringContainsString($msg, $e->getMessage());
            }
        }

        $this->expectException(MembroDaEquipeNaoEncontrado::class);
        $sut->executar($this->primario, $this->tenant, 999, SituacaoNaEquipe::Suspenso);
    }

    /** @param array<int, UserTenant> $porId */
    private function equipe(array $porId): EquipeDoEscritorioRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $repo = $this->createMock(EquipeDoEscritorioRepository::class);
        $repo->method('membroDoEscritorio')->willReturnCallback(static fn (int $id): ?UserTenant => $porId[$id] ?? null);

        return $repo;
    }

    /** @param array<int, UserTenant> $outrosPorUsuario vínculo de cada ator além do primário */
    private function regra(array $outrosPorUsuario = []): RegraDosPrimarios
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn (User $u): ?UserTenant => $u->getId() === 10 ? $this->vinculoPrimario : ($outrosPorUsuario[$u->getId()] ?? null)
        );

        return new RegraDosPrimarios($vinculos);
    }

    /** @param list<string> $papeis */
    private function usuario(int $id, array $papeis = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $u->setFullName('Pessoa ' . $id);

        return $this->comId($u, $id);
    }

    private function vinculo(User $u, bool $primario, int $id): UserTenant
    {
        $perfil = new TenantRole();
        $perfil->setIsSystem($primario);
        $v = new UserTenant($u, $this->tenant);
        $v->setTenantRole($perfil);

        return $this->comId($v, $id);
    }

    /**
     * @template T of object
     *
     * @param T $objeto
     *
     * @return T
     */
    private function comId(object $objeto, int $id): object
    {
        (new \ReflectionProperty($objeto::class, 'id'))->setValue($objeto, $id);

        return $objeto;
    }
}
