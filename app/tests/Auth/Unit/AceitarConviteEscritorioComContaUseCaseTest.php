<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Tests\Ponto\Support\RegistroDePontoInicialDeTesteTrait;
use App\Auth\DTO\AceitarConviteEscritorioComContaInput;
use App\Auth\UseCase\AceitarConviteEscritorioComContaUseCase;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\Service\RegistroDePontoInicial;
use App\Repository\InvitationRepository;
use App\Tests\Auth\Doubles\VagaNoEscritorioDublada;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use App\Auth\Event\ConviteDeEscritorioAceito;

#[CoversClass(AceitarConviteEscritorioComContaUseCase::class)]
final class AceitarConviteEscritorioComContaUseCaseTest extends TestCase
{
    use RegistroDePontoInicialDeTesteTrait;

    use VagaNoEscritorioDublada;
    private EventDispatcher $eventos;

    private InvitationRepository&MockObject $invitationRepo;
    private UserTenantRepository&MockObject $userTenantRepo;
    private EntityManagerInterface&MockObject $em;
    private AceitarConviteEscritorioComContaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->invitationRepo = $this->createMock(InvitationRepository::class);
        $this->userTenantRepo = $this->createMock(UserTenantRepository::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->useCase        = new AceitarConviteEscritorioComContaUseCase(
            $this->invitationRepo,
            $this->userTenantRepo,
            $this->em,
            new RegraDosPrimarios($this->userTenantRepo),
            $this->eventos = new EventDispatcher(),
            $this->vagaNoEscritorio(),
            $this->registroDePontoInicialSemChoque(),
        );
        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('usuario@example.com');
    }

    private function makePendingOfficeInvitation(
        string $email,
        bool $expirada = false,
        ?TenantRole $role = null,
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
        if ($role !== null) {
            $invitation->setTenantRole($role);
        }

        return $invitation;
    }

    /**
     * O conceito de demissão não existe mais (UserTenant::demitir() foi enterrado) — um vínculo
     * inativo só sobra hoje como legado (spec §6.5/§6.6). Para simular esse estado sem recriar
     * o método, marca `isActive` direto via reflection.
     */
    private function criarVinculoInativo(User $user, Tenant $tenant): UserTenant
    {
        $vinculo = new UserTenant($user, $tenant);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);

        return $vinculo;
    }

    public function testHappyPathCriaUserTenantEAceita(): void
    {
        $capturados = [];
        $this->eventos->addListener(ConviteDeEscritorioAceito::class, static function (object $e) use (&$capturados): void { $capturados[] = $e; });
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(UserTenant::class));
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertInstanceOf(UserTenant::class, $resultado);
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($this->usuario, $invitation->getAcceptedAsUser());
        self::assertCount(1, $capturados, 'vínculo novo');
    }

    public function testSuspensoNaoVoltaPeloConvite(): void
    {
        // Spec equipe-da-conta §2: suspenso ou em análise só volta pela equipe da conta, pelo primário.
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $vinculo = new UserTenant($this->usuario, $this->tenant);
        $vinculo->alterarSituacao(\App\Tenant\Enum\SituacaoNaEquipe::Suspenso);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculo);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
            self::fail('o convite não pode reativar quem está suspenso');
        } catch (\DomainException $e) {
            self::assertStringContainsString('está suspenso pelo usuário primário', $e->getMessage());
        }
        self::assertFalse($vinculo->isActive());
        self::assertSame('pending', $invitation->getStatus());
    }

    public function testTenantRoleNaInvitationPropagadoParaUserTenant(): void
    {
        $role       = new TenantRole();
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', role: $role);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame($role, $resultado->getTenantRole());
    }

    public function testSemTenantRoleNaInvitationUserTenantSemRole(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertNull($resultado->getTenantRole());
    }

    public function testTokenNaoEncontradoLancaExcecao(): void
    {
        $this->invitationRepo->method('encontrarPorToken')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('invalido', $this->usuario));
    }

    public function testStatusNaoPendingLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $invitation->revogar();
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não está mais disponível');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testInvitationExpiradaLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', expirada: true);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirado');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testEmailNaoCorrespondeAoUsuarioLancaExcecao(): void
    {
        $invitation = $this->makePendingOfficeInvitation('outro@example.com');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pertence');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testEmailDifereSoEmCaseAceita(): void
    {
        $invitation = $this->makePendingOfficeInvitation('USUARIO@EXAMPLE.COM');
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame('accepted', $invitation->getStatus());
        self::assertInstanceOf(UserTenant::class, $resultado);
    }

    public function testJaEColaboradorAtivoLancaExcecao(): void
    {
        $invitation     = $this->makePendingOfficeInvitation('usuario@example.com');
        $vinculoAtivo   = new UserTenant($this->usuario, $this->tenant);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoAtivo);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('já é colaborador');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    public function testReativaVinculoInativoAoAceitarConvite(): void
    {
        $capturados = [];
        $this->eventos->addListener(ConviteDeEscritorioAceito::class, static function (object $e) use (&$capturados): void { $capturados[] = $e; });
        $invitation     = $this->makePendingOfficeInvitation('usuario@example.com');
        $vinculoInativo = $this->criarVinculoInativo($this->usuario, $this->tenant);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoInativo);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame($vinculoInativo, $resultado);
        self::assertTrue($resultado->isActive());
        self::assertSame('accepted', $invitation->getStatus());
        self::assertSame($this->usuario, $invitation->getAcceptedAsUser());
        self::assertCount(1, $capturados, 'reativação: o Comercial deduplica as boas-vindas, o evento sai igual');
    }

    #[TestDox('Convite que reativa o vínculo: com a jornada daqui batendo com outro escritório onde a pessoa registra ponto, a chave fica desligada')]
    public function testReativarComChoqueDesligaAChave(): void
    {
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 1);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->usuario, 7);
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 2);
        $vinculoInativo = $this->criarVinculoInativo($this->usuario, $this->tenant);
        $this->invitationRepo->method('encontrarPorToken')->willReturn($this->makePendingOfficeInvitation('usuario@example.com'));
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoInativo);
        $this->userTenantRepo->method('findActiveByUser')->willReturn([$vinculoInativo, new UserTenant($this->usuario, $outro)]);
        $manha = (new JornadaColaborador())->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1])->setEntrada('08:00')->setSaida('12:00'));
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('findByUserETenant')->willReturn($manha);   // a mesma manhã aqui e no outro escritório
        $intervalos = new IntervalosDeTrabalho(new JornadaResolver(), $jornadas, $this->createStub(JornadaTenantRepository::class));
        $useCase = new AceitarConviteEscritorioComContaUseCase(
            $this->invitationRepo,
            $this->userTenantRepo,
            $this->em,
            new RegraDosPrimarios($this->userTenantRepo),
            $this->eventos,
            $this->vagaNoEscritorio(),
            new RegistroDePontoInicial($intervalos, new ChoqueDeJornadaEntreEscritorios($intervalos, $jornadas, $this->userTenantRepo), $this->userTenantRepo),
        );

        $resultado = $useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));

        self::assertTrue($resultado->isActive());
        self::assertFalse($resultado->isRegistraPonto());
    }

    public function testAtualizaTenantRoleAoReativarVinculo(): void
    {
        $roleAntiga     = new TenantRole();
        $roleNova       = new TenantRole();
        $invitation     = $this->makePendingOfficeInvitation('usuario@example.com', role: $roleNova);
        $vinculoInativo = $this->criarVinculoInativo($this->usuario, $this->tenant);
        $vinculoInativo->setTenantRole($roleAntiga);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoInativo);
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame($roleNova, $resultado->getTenantRole());
    }

    public function testReativarComConviteSemRoleResetaRoleParaNull(): void
    {
        $roleAntiga     = new TenantRole();
        $invitation     = $this->makePendingOfficeInvitation('usuario@example.com');
        $vinculoInativo = $this->criarVinculoInativo($this->usuario, $this->tenant);
        $vinculoInativo->setTenantRole($roleAntiga);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoInativo);
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertNull($resultado->getTenantRole());
    }

    public function testPreservaDataAdmissaoECodigoFuncionarioAoReativar(): void
    {
        $dataAdmissao   = new \DateTimeImmutable('2023-01-15');
        $invitation     = $this->makePendingOfficeInvitation('usuario@example.com');
        $vinculoInativo = $this->criarVinculoInativo($this->usuario, $this->tenant);
        $vinculoInativo->setDataAdmissao($dataAdmissao);
        $vinculoInativo->setCodigoFuncionario('F-0042');

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn($vinculoInativo);
        $this->em->method('flush');

        $resultado = $this->useCase->executar(
            new AceitarConviteEscritorioComContaInput('tok123', $this->usuario)
        );

        self::assertSame($dataAdmissao, $resultado->getDataAdmissao());
        self::assertSame('F-0042', $resultado->getCodigoFuncionario());
    }

    public function testInvitationSemTenantLancaExcecao(): void
    {
        $invitation = new Invitation(
            email: 'usuario@example.com',
            tokenHash: Invitation::hashDoToken('tok123'),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('escritório não encontrado');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput('tok123', $this->usuario));
    }

    #[TestDox('convite de primário: recusado se quem convidou não pode mais gerir primários; nenhum vínculo criado')]
    public function testConviteDePrimarioDeQuemPerdeuOPoder(): void
    {
        $sistema = (new TenantRole())->setIsSystem(true);
        $quemConvidou = (new User())->setEmail('ex-primario@example.com');
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', role: $sistema);
        $invitation->setCreatedBy($quemConvidou);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        // Hoje quem convidou é secundário no escritório.
        $this->userTenantRepo->method('findAtivoPorUserETenant')
            ->willReturn((new UserTenant($quemConvidou, $this->tenant))->setTenantRole(new TenantRole()));
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->expectExceptionMessageMatches('/Peça um novo convite/');

        $this->useCase->executar(new AceitarConviteEscritorioComContaInput(token: 'tok123', usuarioAtual: $this->usuario));
    }

    #[TestDox('convite de primário: aceito quando quem convidou segue primário ativo')]
    public function testConviteDePrimarioDePrimarioAtivo(): void
    {
        $capturados = [];
        $this->eventos->addListener(ConviteDeEscritorioAceito::class, static function (ConviteDeEscritorioAceito $e) use (&$capturados): void { $capturados[] = $e; });
        $sistema = (new TenantRole())->setIsSystem(true);
        $quemConvidou = (new User())->setEmail('primario@example.com');
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com', role: $sistema);
        $invitation->setCreatedBy($quemConvidou);

        $this->invitationRepo->method('encontrarPorToken')->willReturn($invitation);
        $this->userTenantRepo->method('findAtivoPorUserETenant')
            ->willReturn((new UserTenant($quemConvidou, $this->tenant))->setTenantRole($sistema));
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);
        $this->em->expects($this->once())->method('flush');

        $vinculo = $this->useCase->executar(new AceitarConviteEscritorioComContaInput(token: 'tok123', usuarioAtual: $this->usuario));

        self::assertSame($sistema, $vinculo->getTenantRole());
        self::assertTrue($capturados[0]->comoPrimario, 'convite com o papel de sistema: o evento diz que entrou como primário');
    }

    #[TestDox('Pelo id (lista do convidado logado): busca só convite de escritório pelo id e aceita')]
    public function testPeloIdAceita(): void
    {
        $invitation = $this->makePendingOfficeInvitation('usuario@example.com');
        $this->invitationRepo->expects($this->never())->method('encontrarPorToken');
        $this->invitationRepo->expects($this->once())->method('findOneBy')
            ->with(['id' => 7, 'type' => Invitation::TIPO_ESCRITORIO])->willReturn($invitation);
        $this->userTenantRepo->method('findPorUserETenant')->willReturn(null);

        $this->useCase->executar(AceitarConviteEscritorioComContaInput::porId(7, $this->usuario));

        self::assertSame('accepted', $invitation->getStatus());
    }

    #[TestDox('Pelo id com o e-mail da conta a confirmar: recusa sem nem buscar o convite')]
    public function testPeloIdComEmailAConfirmarRecusa(): void
    {
        $this->usuario->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->invitationRepo->expects($this->never())->method('findOneBy');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(AceitarConviteEscritorioComContaUseCase::RECUSA_EMAIL_A_CONFIRMAR);

        $this->useCase->executar(AceitarConviteEscritorioComContaInput::porId(7, $this->usuario));
    }

    #[TestDox('Pelo id, convite de outro e-mail: recusa e nada é gravado')]
    public function testPeloIdDeOutroEmailRecusa(): void
    {
        $invitation = $this->makePendingOfficeInvitation('outra@example.com');
        $this->invitationRepo->method('findOneBy')->willReturn($invitation);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este convite não pertence à sua conta.');

        $this->useCase->executar(AceitarConviteEscritorioComContaInput::porId(7, $this->usuario));
    }
}
