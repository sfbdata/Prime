<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\InscricaoDePushInput;
use App\Agenda\Entity\Evento;
use App\Agenda\Entity\InscricaoDePush;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Repository\InscricaoDePushRepository;
use App\Agenda\Repository\PushEnviadoRepository;
use App\Agenda\Service\OcorrenciasDoEvento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\UseCase\CancelarInscricaoDePushUseCase;
use App\Agenda\UseCase\InscreverPushUseCase;
use App\Agenda\UseCase\ListarLembretesUseCase;
use App\Agenda\UseCase\VarrerLembretesDePushUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Service\PermissionChecker;
use App\Tests\Agenda\EnviadorDePushFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(VarrerLembretesDePushUseCase::class)]
#[CoversClass(InscreverPushUseCase::class)]
#[CoversClass(CancelarInscricaoDePushUseCase::class)]
final class PushUseCasesTest extends TestCase
{
    private const P256DH = 'BAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A';
    private const AUTH = 'AAECAwQFBgcICQoLDA0ODw';

    #[TestDox('O aviso sai na hora de parede do fuso do navegador: em Manaus, uma hora depois de Brasília')]
    public function testFusoDoNavegador(): void
    {
        $evento = (new Evento())->setTitulo('Audiência')->setDataInicio(new \DateTimeImmutable('2030-03-10 10:00'))->setDataFim(new \DateTimeImmutable('2030-03-10 11:00'))->setLembreteMinutos(15);
        $falso = new EnviadorDePushFalso();
        $manaus = new InscricaoDePush(new Tenant(), new User(), 'https://push/m', 'BOr5', 'YXV0', 'America/Manaus');

        // 09:50 em Brasília = 08:50 em Manaus: o aviso das 09:45 (parede) ainda não venceu lá.
        self::assertSame(0, $this->varrer($evento, $manaus, $falso)->executar(new \DateTimeImmutable('2030-03-10 09:50', new \DateTimeZone('America/Sao_Paulo'))));
        // 10:50 em Brasília = 09:50 em Manaus: venceu.
        self::assertSame(1, $this->varrer($evento, $manaus, $falso)->executar(new \DateTimeImmutable('2030-03-10 10:50', new \DateTimeZone('America/Sao_Paulo'))));
        self::assertSame('Às 10:00', $falso->enviados[0]['conteudo']['corpo']);
    }

    #[TestDox('Audiência de pasta vai por push só a quem tem "Ver prazos na agenda"; o módulo Pastas sozinho não basta')]
    public function testPrazoSoComVerPrazos(): void
    {
        $semLembrete = (new Evento())->setTitulo('Sem lembrete')->setDataInicio(new \DateTimeImmutable('2030-03-10 10:00'))->setDataFim(new \DateTimeImmutable('2030-03-10 11:00'))->setLembreteMinutos(0);
        $audiencia = new PastaPrazo(new Tenant(), (new Pasta())->setNup('1180'), null);
        $audiencia->definir(PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', new \DateTimeImmutable('2030-03-10'), '10:00', null);
        $inscricao = new InscricaoDePush(new Tenant(), new User(), 'https://push/p', 'BOr5', 'YXV0', 'America/Sao_Paulo');
        $quando = new \DateTimeImmutable('2030-03-10 09:30', new \DateTimeZone('America/Sao_Paulo'));
        $falso = new EnviadorDePushFalso();

        self::assertSame(1, $this->varrer($semLembrete, $inscricao, $falso, [$audiencia])->executar($quando));
        self::assertSame('BlueJus · Audiência · Pasta 1180', $falso->enviados[0]['conteudo']['titulo']);
        self::assertSame(0, $this->varrer($semLembrete, $inscricao, new EnviadorDePushFalso(), [$audiencia], false)->executar($quando));
    }

    #[TestDox('Inscrição de serviço de push conhecido e chaves do tamanho certo é gravada; fuso estranho vira o de Brasília')]
    public function testInscrever(): void
    {
        $salvas = [];
        $sut = $this->inscrever($salvas);

        $sut->executar(new Tenant(), new User(), new InscricaoDePushInput('https://fcm.googleapis.com/fcm/send/x', self::P256DH, self::AUTH, 'Lua/Base'));
        $sut->executar(new Tenant(), new User(), new InscricaoDePushInput('https://web.push.apple.com/abc', self::P256DH, self::AUTH, 'America/Manaus'));
        self::assertSame('America/Sao_Paulo', $salvas[0]->getFuso());
        self::assertSame('America/Manaus', $salvas[1]->getFuso());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function inscricoesRecusadas(): iterable
    {
        yield 'http sem cifra' => ['http://fcm.googleapis.com/fcm/send/x', self::P256DH, self::AUTH];
        yield 'endereço interno (o servidor não chama endereço qualquer)' => ['https://10.0.0.5/x', self::P256DH, self::AUTH];
        yield 'host que só imita o serviço' => ['https://fcm.googleapis.com.mal.example/x', self::P256DH, self::AUTH];
        yield 'chave curta (derrubaria a cifra)' => ['https://fcm.googleapis.com/fcm/send/x', 'BOr5QnS8RTc', self::AUTH];
        yield 'chave sem o 0x04 do ponto' => ['https://fcm.googleapis.com/fcm/send/x', 'A' . substr(self::P256DH, 1), self::AUTH];
        yield 'auth com tamanho errado' => ['https://fcm.googleapis.com/fcm/send/x', self::P256DH, 'YXV0aA'];
    }

    #[DataProvider('inscricoesRecusadas')]
    #[TestDox('Recusa: $_dataName')]
    public function testRecusa(string $endpoint, string $p256dh, string $auth): void
    {
        $salvas = [];
        $this->expectException(\DomainException::class);
        $this->inscrever($salvas)->executar(new Tenant(), new User(), new InscricaoDePushInput($endpoint, $p256dh, $auth, 'UTC'));
    }

    #[TestDox('Lembrete que começou há mais de 2 h não sai mais por push')]
    public function testCorteDeDuasHoras(): void
    {
        $evento = (new Evento())->setTitulo('Reunião')->setDataInicio(new \DateTimeImmutable('2030-03-10 10:00'))->setDataFim(new \DateTimeImmutable('2030-03-10 11:00'))->setLembreteMinutos(15);
        $inscricao = new InscricaoDePush(new Tenant(), new User(), 'https://push/s', self::P256DH, self::AUTH, 'America/Sao_Paulo');
        $sp = new \DateTimeZone('America/Sao_Paulo');

        self::assertSame(1, $this->varrer($evento, $inscricao, new EnviadorDePushFalso())->executar(new \DateTimeImmutable('2030-03-10 12:00', $sp)));
        self::assertSame(0, $this->varrer($evento, $inscricao, new EnviadorDePushFalso())->executar(new \DateTimeImmutable('2030-03-10 12:01', $sp)));
    }

    #[TestDox('Cancelar remove pelo endereço e pela própria pessoa')]
    public function testCancelar(): void
    {
        $eu = new User();
        $repo = $this->createMock(InscricaoDePushRepository::class);
        $repo->expects(self::once())->method('removerDaPessoa')->with('https://p/1', $eu);

        (new CancelarInscricaoDePushUseCase($repo))->executar($eu, 'https://p/1');
    }

    /** @param list<InscricaoDePush> $salvas */
    private function inscrever(array &$salvas): InscreverPushUseCase
    {
        $repo = $this->createMock(InscricaoDePushRepository::class);
        $repo->method('gravar')->willReturnCallback(static function (InscricaoDePush $i) use (&$salvas): void {
            $salvas[] = $i;
        });

        return new InscreverPushUseCase($repo);
    }

    /** @param list<PastaPrazo> $prazos */
    private function varrer(Evento $evento, InscricaoDePush $inscricao, EnviadorDePushFalso $falso, array $prazos = [], bool $verPrazos = true): VarrerLembretesDePushUseCase
    {
        $eventos = $this->createStub(EventoDaAgendaRepository::class);
        $eventos->method('doEscritorioNoPeriodo')->willReturn([$evento]);
        $inscricoes = $this->createStub(InscricaoDePushRepository::class);
        $inscricoes->method('todas')->willReturn([$inscricao]);
        $enviados = $this->createStub(PushEnviadoRepository::class);
        $enviados->method('reservar')->willReturn(true);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn(true);
        $permissoes->method('hasPermission')->willReturnCallback(static fn (User $u, ?Tenant $t, string $codigo): bool => $verPrazos && PermissoesDaAgenda::VER_PRAZOS === $codigo);
        $repoDePrazos = $this->createStub(PastaPrazoRepository::class);
        $repoDePrazos->method('abertosDoEscritorioNoPeriodo')->willReturn($prazos);
        $listar = new ListarLembretesUseCase($eventos, new OcorrenciasDoEvento(), $repoDePrazos);

        return new VarrerLembretesDePushUseCase($inscricoes, $enviados, $listar, $permissoes, $falso, new PermissoesDaAgenda($permissoes));
    }
}
