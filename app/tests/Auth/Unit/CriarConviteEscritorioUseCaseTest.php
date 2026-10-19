<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\CriarConviteEscritorioInput;
use App\Auth\UseCase\CriarConviteEscritorioUseCase;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\UsuariosDoEscritorioRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarConviteEscritorioUseCase::class)]
final class CriarConviteEscritorioUseCaseTest extends TestCase
{
    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private UserTenantRepository&MockObject $userTenantRepo;
    private EntityManagerInterface&MockObject $em;
    private CriarConviteEscritorioUseCase $useCase;
    private Tenant $tenant;
    private TenantRole $role;
    private User $criadoPor;
    private ConfiguracaoDoEspaco $configuracao;
    private UsuariosDoEscritorioRepository&MockObject $usuariosDoEscritorio;

    protected function setUp(): void
    {
        $this->invitationRepo  = $this->createMock(InvitationRepository::class);
        $this->userRepo        = $this->createMock(UserRepository::class);
        $this->userTenantRepo  = $this->createMock(UserTenantRepository::class);
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->configuracao    = new ConfiguracaoDoEspaco();
        $this->usuariosDoEscritorio = $this->createMock(UsuariosDoEscritorioRepository::class);
        $this->useCase         = new CriarConviteEscritorioUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->userTenantRepo,
            $this->em,
            new RegraDosPrimarios($this->userTenantRepo),
            $this->situacaoDoEspaco(),
            $this->usuariosDoEscritorio,
        );
        $this->tenant    = new Tenant();
        $this->role      = new TenantRole();
        $this->criadoPor = (new User())->setEmail('admin@escritorio.com');
    }

    private function input(string $email, ?string $fullName = null): CriarConviteEscritorioInput
    {
        return new CriarConviteEscritorioInput(
            email: $email,
            fullName: $fullName,
            tenant: $this->tenant,
            tenantRole: $this->role,
            criadoPor: $this->criadoPor,
        );
    }

    public function testEmailSemContaCriaConviteEscritorio(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->input('novo@example.com'));

        self::assertSame('office', $resultado->getType());
        self::assertSame('pending', $resultado->getStatus());
        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertSame($this->role, $resultado->getTenantRole());

        $diff = $resultado->getExpiresAt()->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        self::assertEqualsWithDelta(7 * 86400, $diff, 5);
    }

    public function testEmailComContaSemVinculoAtivoCriaConvite(): void
    {
        $existingUser = new User();
        $this->userRepo->method('findOneBy')->willReturn($existingUser);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(false);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->input('existente@example.com'));

        self::assertSame('pending', $resultado->getStatus());
    }

    public function testEmailComContaComVinculoAtivoLancaExcecao(): void
    {
        $existingUser = new User();
        $this->userRepo->method('findOneBy')->willReturn($existingUser);
        $this->userTenantRepo->method('existeVinculoAtivo')->willReturn(true);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('colaborador ativo');

        $this->useCase->executar($this->input('membro@example.com'));
    }

    public function testEmailNormalizadoUpperEspacos(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->input('  CONVIDADO@EXAMPLE.COM  '));

        self::assertSame('convidado@example.com', $resultado->getEmail());
    }

    public function testExisteVinculoNaoChamadoSeUserNaoExiste(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->userTenantRepo->expects($this->never())->method('existeVinculoAtivo');
        $this->em->method('persist');
        $this->em->method('flush');

        $this->useCase->executar($this->input('novo@example.com'));
    }

    public function testTenantERolePropagados(): void
    {
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->input('novo@example.com', 'Maria'));

        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertSame($this->role, $resultado->getTenantRole());
        self::assertSame('Maria', $resultado->getFullName());
    }

    #[TestDox('convite com o perfil de sistema (primário) é recusado para quem não é primário nem Master')]
    public function testConviteComoPrimarioRecusadoParaSecundario(): void
    {
        $this->role->setIsSystem(true);
        $this->userTenantRepo->method('findAtivoPorUserETenant')->willReturn(
            (new UserTenant($this->criadoPor, $this->tenant))->setTenantRole(new TenantRole())
        );
        $this->em->expects($this->never())->method('persist');

        $this->expectException(OperacaoDePrimarioRecusada::class);

        $this->useCase->executar($this->input('novo@escritorio.com'));
    }

    #[TestDox('convite com o perfil de sistema é aceito quando quem convida é primário ativo')]
    public function testConviteComoPrimarioAceitoParaPrimario(): void
    {
        $this->role->setIsSystem(true);
        $this->criadoPor->setIsActive(true);
        $this->userTenantRepo->method('findAtivoPorUserETenant')->willReturn(
            (new UserTenant($this->criadoPor, $this->tenant))->setTenantRole($this->role)
        );
        $this->em->expects($this->once())->method('persist');

        $this->useCase->executar($this->input('novo@escritorio.com'));
    }

    #[TestDox('convite com o perfil de sistema é aceito para o Master')]
    public function testConviteComoPrimarioAceitoParaMaster(): void
    {
        $this->role->setIsSystem(true);
        $this->criadoPor->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em->expects($this->once())->method('persist');

        $this->useCase->executar($this->input('novo@escritorio.com'));
    }

    #[TestDox('limite de usuários (P8): com o bloqueio ligado e o Free (3) cheio, o convite é recusado com a mensagem')]
    public function testLimiteDeUsuariosComBloqueioLigadoRecusa(): void
    {
        $this->configuracao->ligarBloqueio(true);
        $this->usuariosDoEscritorio->method('quantos')->willReturn(3);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este escritório chegou ao limite de 3 usuários. Para convidar mais pessoas, amplie o plano ou fale com o suporte do BlueJus.');

        $this->useCase->executar($this->input('quarto@escritorio.com'));
    }

    #[TestDox('limite de usuários (P8): com o bloqueio ligado e vaga sobrando, o convite sai')]
    public function testLimiteDeUsuariosComVagaAceita(): void
    {
        $this->configuracao->ligarBloqueio(true);
        $this->usuariosDoEscritorio->method('quantos')->willReturn(2);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');

        $this->useCase->executar($this->input('terceiro@escritorio.com'));
    }

    #[TestDox('limite de usuários (P8): com o bloqueio desligado (padrão do deploy), nunca recusa')]
    public function testLimiteDeUsuariosComBloqueioDesligadoNaoRecusa(): void
    {
        $this->usuariosDoEscritorio->method('quantos')->willReturn(10);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');

        $this->useCase->executar($this->input('decimo@escritorio.com'));
    }

    /** SituacaoDoEspaco é final: real, com os repositórios da Contribua dublados (escritório no Free, 3 usuários). */
    private function situacaoDoEspaco(): SituacaoDoEspaco
    {
        $consumo = $this->createMock(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn(0);
        $espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenant')->willReturn(null);
        $planos = $this->createMock(PlanoRepository::class);
        $planos->method('free')->willReturn(new Plano(Plano::CODIGO_FREE, 'Free', 10, 0, 'Grátis', 1, 3));
        $configuracoes = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturnCallback(fn (): ConfiguracaoDoEspaco => $this->configuracao);

        return new SituacaoDoEspaco($consumo, $espacos, $planos, $configuracoes);
    }
}
