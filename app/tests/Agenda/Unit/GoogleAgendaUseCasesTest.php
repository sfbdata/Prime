<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Agenda\Repository\EventoDaAgendaRepository;
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

    #[TestDox('Conectar de novo (outra conta) substitui a autorização, cifrada, e recomeça a sincronia do zero; a primeira sincronia é pedida na hora')]
    public function testReconectar(): void
    {
        $existente = new ConexaoGoogleAgenda(new Tenant(), new User(), 'antigo', 'velha@gmail.com', 'UTC');
        $existente->sincronizou('st-velho', new \DateTimeImmutable());
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('daPessoa')->willReturn($existente);
        $repo->method('ocupar')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $api = (new GoogleFalso())->api();
        $cifrador = new CifradorDeSegredo(self::CHAVE);
        $garantir = new GarantirVarreduraDoGoogle($this->createStub(VarreduraDoGoogleRepository::class), $api, $this->createStub(MessageBusInterface::class));

        (new ConectarGoogleAgendaUseCase($api, $repo, $cifrador, $bus, $garantir, $this->createStub(EventoDaAgendaRepository::class), $this->createStub(ExclusaoNoGoogleRepository::class)))->executar(new Tenant(), new User(), 'codigo', 'verificador', 'https://x/agenda/google/retorno');

        self::assertSame('pessoa@gmail.com', $existente->getConta());
        self::assertNull($existente->getSyncToken());
        self::assertSame('REFRESH-SECRETO', $cifrador->decifrar($existente->getRefreshTokenCifrado()));
    }

    #[TestDox('Desconectar revoga no Google, descarta as exclusões pendentes e apaga a conexão; sem conexão, nada')]
    public function testDesconectar(): void
    {
        $falso = new GoogleFalso();
        $conexao = new ConexaoGoogleAgenda(new Tenant(), new User(), (new CifradorDeSegredo(self::CHAVE))->cifrar('R'), 'p@gmail.com', 'UTC');
        $repo = $this->createStub(ConexaoGoogleAgendaRepository::class);
        $repo->method('daPessoa')->willReturnOnConsecutiveCalls($conexao, null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($conexao);
        $sut = new DesconectarGoogleAgendaUseCase($repo, $this->createStub(ExclusaoNoGoogleRepository::class), $falso->api(), new CifradorDeSegredo(self::CHAVE), $em);

        $sut->executar(new Tenant(), new User());
        $sut->executar(new Tenant(), new User());

        self::assertSame(['POST /revoke'], $falso->pedidos);
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
        $montar = fn (GoogleAgendaApi $api): SincronizarGoogleAgendaUseCase => new SincronizarGoogleAgendaUseCase(
            $repo,
            $this->createStub(EventoDaAgendaRepository::class),
            $this->createStub(ExclusaoNoGoogleRepository::class),
            $api,
            new CompromissoNoGoogle(),
            new CifradorDeSegredo(self::CHAVE),
            new SilencioDaSincronia(),
            $permissoes,
            new DesconectarGoogleAgendaUseCase($repo, $this->createStub(ExclusaoNoGoogleRepository::class), $api, new CifradorDeSegredo(self::CHAVE), $this->createStub(EntityManagerInterface::class)),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        self::assertSame(['trazidos' => 0, 'enviados' => 0, 'apagados' => 0], $montar(new GoogleAgendaApi(new MockHttpClient()))->executar(1, new \DateTimeImmutable()));
        self::assertNull($montar($falso->api())->executar(1, new \DateTimeImmutable()));
        self::assertSame([], $falso->pedidos);
    }
}
