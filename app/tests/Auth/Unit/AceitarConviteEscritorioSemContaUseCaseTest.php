<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Tests\Ponto\Support\RegistroDePontoInicialDeTesteTrait;
use App\Auth\DTO\AceitarConviteEscritorioSemContaInput;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\PoliticaDeCadastro;
use App\Auth\UseCase\AceitarConviteEscritorioSemContaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Enum\Genero;
use App\Repository\InvitationRepository;
use App\Tests\Auth\Doubles\VagaNoEscritorioDublada;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use App\Termo\UseCase\RegistrarAceiteTermoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use App\Auth\Event\ConviteDeEscritorioAceito;

#[CoversClass(AceitarConviteEscritorioSemContaUseCase::class)]
final class AceitarConviteEscritorioSemContaUseCaseTest extends TestCase
{
    use RegistroDePontoInicialDeTesteTrait;

    use VagaNoEscritorioDublada;
    private EventDispatcher $eventos;

    private InvitationRepository&MockObject $invitationRepo;
    private UserRepository&MockObject $userRepo;
    private EntityManagerInterface&MockObject $em;
    private UserPasswordHasherInterface&MockObject $hasher;
    private UserTenantRepository&MockObject $userTenantRepo;
    private AceitarConviteEscritorioSemContaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->userRepo       = $this->createMock(UserRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->hasher         = $this->createMock(UserPasswordHasherInterface::class);
        $this->userTenantRepo = $this->createMock(UserTenantRepository::class);

        // Registrar real com repo curto-circuitado (já aceito) — sem efeitos no fluxo do convite.
        $aceiteRepo = $this->createMock(AceiteTermoRepository::class);
        $aceiteRepo->method('existeAceiteVigente')->willReturn(true);
        $registrarAceite = new RegistrarAceiteTermoUseCase($aceiteRepo, new TermoVigente());

        $this->useCase = new AceitarConviteEscritorioSemContaUseCase(
            $this->invitationRepo,
            $this->userRepo,
            $this->em,
            $this->hasher,
            $registrarAceite,
            new RegraDosPrimarios($this->userTenantRepo),
            $this->eventos = new EventDispatcher(),
            $this->vagaNoEscritorio(),
            $this->registroDePontoInicialSemChoque(),
            new PoliticaDeCadastro($this->configuracao = new ConfiguracaoDaPlataformaFalsa(['cadastro' => 'on'])),
        );
        $this->tenant = new Tenant();
    }

    private function makeInvitation(
        string $email = 'novo@example.com',
        ?string $fullName = 'Nome Invitation',
        bool $expirada = false,
    ): Invitation {
        $expiresAt = $expirada
            ? new \DateTimeImmutable('-1 hour')
            : new \DateTimeImmutable('+7 days');

        $invitation = new Invitation(
            email: $email,
            tokenHash: Invitation::hashDoToken('tok123'),
            type: 'office',
            expiresAt: $expiresAt,
        );
        $invitation->setTenant($this->tenant);
        $invitation->setFullName($fullName);

        return $invitation;
    }

    public function testHappyPathFullNameNoInputCriaUserEUserTenant(): void
    {
        $capturados = [];
        $this->eventos->addListener(ConviteDeEscritorioAceito::class, static function (object $e) use (&$capturados): void { $capturados[] = $e; });
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $persistidos = [];
        $this->em->method('persist')->willReturnCallback(function ($obj) use (&$persistidos) {
            $persistidos[] = $obj;
        });
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome do Input', 'senha123', aceiteTermos: true, genero: 'feminino')
        );

        self::assertInstanceOf(User::class, $resultado);
        self::assertSame('Nome do Input', $resultado->getFullName());
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($resultado, $invitation->getAcceptedAsUser());
        self::assertCount(2, $persistidos);
        // O perfil nasce com o gênero do formulário e vai em cascata com o User (spec genero-no-cadastro).
        self::assertSame(Genero::Feminino, $resultado->getProfile()?->getGenero());
        self::assertCount(1, $capturados);
        self::assertFalse($capturados[0]->comoPrimario);
    }

    public function testFullNameVazioUsaNomeDaInvitation(): void
    {
        $invitation = $this->makeInvitation(fullName: 'Nome da Invitation');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', '', 'senha123', aceiteTermos: true, genero: 'feminino')
        );

        self::assertSame('Nome da Invitation', $resultado->getFullName());
    }

    public function testFullNameNoInputTemPrioridadeSobreInvitation(): void
    {
        $invitation = $this->makeInvitation(fullName: 'Nome da Invitation');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome Prioritário', 'senha123', aceiteTermos: true, genero: 'feminino')
        );

        self::assertSame('Nome Prioritário', $resultado->getFullName());
    }

    public function testTenantRoleNaInvitationPropagado(): void
    {
        $role       = new TenantRole();
        $invitation = $this->makeInvitation();
        $invitation->setTenantRole($role);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $userTenantCapturado = null;
        $this->em->method('persist')->willReturnCallback(function ($obj) use (&$userTenantCapturado) {
            if ($obj instanceof \App\Entity\Auth\UserTenant) {
                $userTenantCapturado = $obj;
            }
        });
        $this->em->method('flush');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: 'feminino')
        );

        self::assertNotNull($userTenantCapturado);
        self::assertSame($role, $userTenantCapturado->getTenantRole());
    }

    private ConfiguracaoDaPlataformaFalsa $configuracao;

    #[TestDox('Cadastro só por convite: o convite de escritório ainda cria a conta')]
    public function testPoliticaConviteDeixaOConviteCriarConta(): void
    {
        $this->configuracao->definir('cadastro', 'convite');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makeInvitation());
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $resultado = $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: 'feminino'));

        self::assertInstanceOf(User::class, $resultado);
    }

    #[TestDox('Cadastro fechado: o convite de escritório não cria conta nova e nada é gravado nem consumido')]
    public function testPoliticaOffBloqueiaOConviteDeEscritorio(): void
    {
        $this->configuracao->definir('cadastro', 'off');
        $invitation = $this->makeInvitation();
        $this->invitationRepo->expects($this->never())->method('encontrarPorToken');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: 'feminino'));
            self::fail('Deveria lançar CadastroNaoDisponivel.');
        } catch (CadastroNaoDisponivel $e) {
            self::assertSame('off', $e->politica);
        }
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('invalido', 'Nome', 'senha123', genero: 'feminino'));
    }

    public function testStatusNaoPendingLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', genero: 'feminino'));
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation(expirada: true);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', genero: 'feminino'));
    }

    public function testSenhaCurtaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A senha deve ter ao menos 8 caracteres.');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'curta', aceiteTermos: true, genero: 'feminino'));
    }

    public function testEmailJaTemContaLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([new User()]);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Já existe uma conta');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', genero: 'feminino'));
    }

    public function testFullNameVazioEInvitationNullLancaExcecao(): void
    {
        $invitation = $this->makeInvitation(fullName: null);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nome completo é obrigatório');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', '', 'senha123', genero: 'feminino'));
    }

    public function testInvitationSemTenantLancaExcecao(): void
    {
        $invitation = new Invitation(
            email: 'novo@example.com',
            tokenHash: Invitation::hashDoToken('tok123'),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setFullName('Nome');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('escritório não encontrado');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', genero: 'feminino'));
    }

    public function testSemAceitarTermosLancaExcecao(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Termos de Uso');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: false, genero: 'feminino')
        );
    }

    public function testHashPasswordFoiChamado(): void
    {
        $invitation = $this->makeInvitation();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('findOneBy')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $this->hasher->expects($this->once())
            ->method('hashPassword')
            ->with($this->isInstanceOf(User::class), 'minhasenha')
            ->willReturn('hashed_pass');

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'minhasenha', aceiteTermos: true, genero: 'feminino')
        );
    }

    #[TestDox('convite de primário sem conta: recusado se quem convidou foi desativado; nada é criado')]
    public function testConviteDePrimarioDeContaDesativada(): void
    {
        $sistema = (new TenantRole())->setIsSystem(true);
        $quemConvidou = (new User())->setEmail('ex@example.com')->setIsActive(false);
        $invitation = $this->makeInvitation();
        $invitation->setTenantRole($sistema);
        $invitation->setCreatedBy($quemConvidou);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(OperacaoDePrimarioRecusada::class);

        $this->useCase->executar(
            new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: 'feminino')
        );
    }

    /** @return iterable<string, array{string}> */
    public static function generosRecusados(): iterable
    {
        yield 'vazio' => [''];
        yield 'fora das opções' => ['outro'];
        yield 'legado "prefiro não informar"' => ['nao_informado'];
    }

    #[DataProvider('generosRecusados')]
    #[TestDox('Sem gênero válido (vazio, desconhecido ou o legado) a conta não nasce: "Informe o gênero."')]
    public function testGeneroObrigatorio(string $genero): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makeInvitation());
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Informe o gênero.');

        $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: $genero));
    }

    #[TestDox('Masculino também grava no perfil da conta nova')]
    public function testGravaMasculino(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makeInvitation());
        $this->userRepo->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->hasher->method('hashPassword')->willReturn('hashed');

        $user = $this->useCase->executar(new AceitarConviteEscritorioSemContaInput('tok123', 'Nome', 'senha123', aceiteTermos: true, genero: 'masculino'));

        self::assertSame(Genero::Masculino, $user->getProfile()?->getGenero());
    }
}
