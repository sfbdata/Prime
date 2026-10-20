<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Repository\LigacaoComGoogleRepository;
use App\Agenda\Service\LevarAoGoogle;
use App\Agenda\Service\LevarPrazosAoGoogle;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\Service\PrazoParaOGoogle;
use App\Agenda\Repository\PrazoNoGoogleRepository;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Agenda\Service\ParticipantesDoGoogle;
use App\Repository\UserRepository;
use App\Agenda\Repository\ExclusaoNoGoogleRepository;
use App\Agenda\Repository\VarreduraDoGoogleRepository;
use App\Agenda\Service\GarantirVarreduraDoGoogle;
use App\Agenda\UseCase\ConectarGoogleAgendaUseCase;
use App\Agenda\UseCase\DesconectarGoogleAgendaUseCase;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use App\Agenda\Service\CompromissoNoGoogle;
use App\Agenda\Service\SilencioDaSincronia;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use App\Agenda\UseCase\EstadoDoGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(EstadoDoGoogleAgendaUseCase::class)]
#[CoversClass(ConectarGoogleAgendaUseCase::class)]
#[CoversClass(DesconectarGoogleAgendaUseCase::class)]
#[CoversClass(SincronizarGoogleAgendaUseCase::class)]
final class GoogleAgendaUseCasesTest extends TestCase
{
    private const CHAVE = 'xPdoH8AXaeIJ0M0cWASp9JlffTg4B4tcNFajzAcA+5I=';

    #[TestDox('Estado do painel: sem cliente OAuth, indisponível; sem conexão ou com autorização revogada, não conectado; senão, conectado')]
    public function testEstado(): void
    {
        $conexao = new ConexaoGoogleAgenda(new Tenant(), new User(), 'x', 'p@gmail.com', 'UTC');
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('daPessoa')->willReturnOnConsecutiveCalls(null, $conexao, $conexao);
        $ligada = (new GoogleFalso())->api();

        self::assertSame(EstadoDoGoogleAgendaUseCase::INDISPONIVEL, (new EstadoDoGoogleAgendaUseCase(new GoogleAgendaApi(new MockHttpClient()), $repo))->executar(new Tenant(), new User()));
        $sut = new EstadoDoGoogleAgendaUseCase($ligada, $repo);
        self::assertSame(EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, $sut->executar(new Tenant(), new User()));
        self::assertSame(EstadoDoGoogleAgendaUseCase::CONECTADO, $sut->executar(new Tenant(), new User()));
        $conexao->falhou(ConexaoGoogleAgenda::ERRO_AUTORIZACAO);
        self::assertSame(EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, $sut->executar(new Tenant(), new User()));
    }

    #[TestDox('Conectar de novo (outra conta) substitui a autorização, cifrada, com o e-mail do id_token, e recomeça do zero numa agenda nova; a primeira sincronia é pedida na hora')]
    public function testReconectar(): void
    {
        $existente = new ConexaoGoogleAgenda(new Tenant(), new User(), 'antigo', 'velha@gmail.com', 'UTC');
        $existente->agendaCriada('agenda-velha');
        $existente->sincronizou('st-velho', new \DateTimeImmutable());
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('daPessoa')->willReturn($existente);
        $repo->method('ocupar')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $api = (new GoogleFalso())->api();
        $cifrador = new CifradorDeSegredo(self::CHAVE);
        $garantir = new GarantirVarreduraDoGoogle($this->createStub(VarreduraDoGoogleRepository::class), $api, $this->createStub(MessageBusInterface::class));

        (new ConectarGoogleAgendaUseCase($api, $repo, $cifrador, $bus, $garantir, $this->createStub(ExclusaoNoGoogleRepository::class), $this->createStub(LigacaoComGoogleRepository::class)))->executar(new Tenant(), new User(), 'codigo', 'verificador', 'https://x/agenda/google/retorno');

        self::assertSame('pessoa@gmail.com', $existente->getConta());
        self::assertNull($existente->getSyncToken());
        self::assertNull($existente->getCalendarioId(), 'outra conta, outra agenda do escritório');
        self::assertSame('REFRESH-SECRETO', $cifrador->decifrar($existente->getRefreshTokenCifrado()));
    }

    #[TestDox('Desconectar revoga no Google e apaga a conexão; mantém a agenda do escritório lá, salvo se a pessoa pedir; sem conexão, nada')]
    public function testDesconectar(): void
    {
        foreach ([false => [], true => ['DELETE /calendar/v3/calendars/agenda-1']] as $apagar => $agenda) {
            $falso = new GoogleFalso();
            $conexao = new ConexaoGoogleAgenda(new Tenant(), new User(), (new CifradorDeSegredo(self::CHAVE))->cifrar('R'), 'p@gmail.com', 'UTC');
            $conexao->agendaCriada('agenda-1');
            $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
            $repo->method('daPessoa')->willReturnOnConsecutiveCalls($conexao, null);
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::once())->method('remove')->with($conexao);
            $sut = new DesconectarGoogleAgendaUseCase($repo, $this->createStub(ExclusaoNoGoogleRepository::class), $falso->api(), new CifradorDeSegredo(self::CHAVE), $em);

            $sut->executar(new Tenant(), new User(), (bool) $apagar);
            $sut->executar(new Tenant(), new User(), (bool) $apagar);

            $semToken = array_values(array_filter($falso->pedidos, static fn (string $p): bool => !str_ends_with($p, '/token')));
            self::assertSame([...$agenda, 'POST /revoke'], $semToken);
        }
    }

    #[TestDox('Desconectar não revoga a conta que continua ligada em outro escritório (derrubaria a de lá)')]
    public function testDesconectarContaEmOutroEscritorio(): void
    {
        $falso = new GoogleFalso();
        $conexao = new ConexaoGoogleAgenda(new Tenant(), new User(), (new CifradorDeSegredo(self::CHAVE))->cifrar('R'), 'p@gmail.com', 'UTC');
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('daPessoa')->willReturn($conexao);
        $repo->method('contaLigadaEmOutraConexao')->willReturn(true);

        (new DesconectarGoogleAgendaUseCase($repo, $this->createStub(ExclusaoNoGoogleRepository::class), $falso->api(), new CifradorDeSegredo(self::CHAVE), $this->createStub(EntityManagerInterface::class)))->executar(new Tenant(), new User(), false);

        self::assertNotContains('POST /revoke', $falso->pedidos);
    }

    #[TestDox('Sincronia: com o Google desligado não faz nada; com a trava ocupada devolve nulo, sem chamar o Google')]
    public function testSincronizarDesligadoOuOcupado(): void
    {
        $conexao = new ConexaoGoogleAgenda(new Tenant(), new User(), 'x', 'p@gmail.com', 'UTC');
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('find')->willReturn($conexao);
        $repo->method('ocupar')->willReturn(false);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn(true);
        $falso = new GoogleFalso();
        $ligacoes = $this->createStub(LigacaoComGoogleRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $montar = fn (GoogleAgendaApi $api): SincronizarGoogleAgendaUseCase => new SincronizarGoogleAgendaUseCase(
            $repo,
            $ligacoes,
            $api,
            new CompromissoNoGoogle(),
            new LevarAoGoogle($api, new CompromissoNoGoogle(), $this->createStub(EventoDaAgendaRepository::class), $this->createStub(ExclusaoNoGoogleRepository::class), $ligacoes, $em, new NullLogger()),
            new ParticipantesDoGoogle($this->createStub(UserRepository::class), $repo),
            new CifradorDeSegredo(self::CHAVE),
            new SilencioDaSincronia(),
            $permissoes,
            new DesconectarGoogleAgendaUseCase($repo, $this->createStub(ExclusaoNoGoogleRepository::class), $api, new CifradorDeSegredo(self::CHAVE), $this->createStub(EntityManagerInterface::class)),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new LevarPrazosAoGoogle($api, new PrazoParaOGoogle(), $this->createStub(PastaPrazoRepository::class), $this->createStub(PrazoNoGoogleRepository::class), new PermissoesDaAgenda($permissoes), $em, new NullLogger()),
            $this->createStub(PrazoNoGoogleRepository::class),
        );

        self::assertSame(['trazidos' => 0, 'enviados' => 0, 'apagados' => 0], $montar(new GoogleAgendaApi(new MockHttpClient()))->executar(1, new \DateTimeImmutable()));
        self::assertNull($montar($falso->api())->executar(1, new \DateTimeImmutable()));
        self::assertSame([], $falso->pedidos);
    }
}
