<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\CompromissoInput;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\AvisoDoCompromisso;
use App\Agenda\UseCase\SalvarCompromissoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(SalvarCompromissoUseCase::class)]
#[CoversClass(AvisoDoCompromisso::class)]
#[CoversClass(CompromissoInput::class)]
final class SalvarCompromissoUseCaseTest extends TestCase
{
    private EventoDaAgendaRepository&MockObject $eventos;
    private PastaRepository&MockObject $pastas;
    private UserRepository&MockObject $usuarios;
    private PermissionChecker&MockObject $permissoes;
    private NotificacaoService&MockObject $notificacoes;
    private User $eu;
    private User $maria;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->eventos = $this->createMock(EventoDaAgendaRepository::class);
        $this->pastas = $this->createMock(PastaRepository::class);
        $this->usuarios = $this->createMock(UserRepository::class);
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->tenant = new Tenant();
        $this->eu = $this->usuario(1, 'Samuel Rocha');
        $this->maria = $this->usuario(2, 'Maria Clara Souza');
        $this->usuarios->method('findColaboradoresAtivosPorTenant')->willReturnCallback(fn (): array => [$this->eu, $this->maria]);
    }

    #[TestDox('Cria compromisso de dia inteiro, só para mim, com tipo, cor do tipo e lembrete')]
    public function testCriaDiaInteiroPrivado(): void
    {
        $salvo = null;
        $this->eventos->expects(self::once())->method('salvar')->willReturnCallback(static function (Evento $e) use (&$salvo): void {
            $salvo = $e;
        });

        $r = $this->sut()->executar($this->input(['diaInteiro' => true, 'visibilidade' => 'privado', 'tipo' => 'aud', 'lembreteMinutos' => 60]), null, $this->eu, $this->tenant);

        self::assertSame('2030-03-10', $r->data);
        self::assertSame('2030-03-10 00:00:00', $salvo->getDataInicio()->format('Y-m-d H:i:s'));
        self::assertSame('2030-03-10 23:59:59', $salvo->getDataFim()->format('Y-m-d H:i:s'));
        self::assertSame(Evento::VISIBILIDADE_SOMENTE_EU, $salvo->getVisibilidade());
        self::assertSame(TipoDeCompromisso::Audiencia, $salvo->getTipo());
        self::assertSame('#5b3f9e', $salvo->getCor());
        self::assertSame(60, $salvo->getLembreteMinutos());
        self::assertSame($this->eu, $salvo->getCriador());
    }

    #[TestDox('Fim antes do início é recusado')]
    public function testFimAntesDoInicio(): void
    {
        $this->eventos->expects(self::never())->method('salvar');
        $this->expectExceptionMessage('O fim precisa ser depois do início.');

        $this->sut()->executar($this->input(['inicio' => '10:00', 'fim' => '09:00']), null, $this->eu, $this->tenant);
    }

    #[TestDox('Pasta que não é do escritório é recusada; a do escritório é ligada')]
    public function testPasta(): void
    {
        $pasta = (new Pasta())->setNup('1180');
        $this->pastas->method('findDoEscritorioPorNup')->willReturnCallback(static fn (string $nup): ?Pasta => '1180' === $nup ? $pasta : null);
        $salvo = null;
        $this->eventos->method('salvar')->willReturnCallback(static function (Evento $e) use (&$salvo): void {
            $salvo = $e;
        });

        $this->sut()->executar($this->input(['pasta' => '1180']), null, $this->eu, $this->tenant);
        self::assertSame($pasta, $salvo->getPasta());

        $this->expectExceptionMessage('Pasta 9999 não encontrada neste escritório.');
        $this->sut()->executar($this->input(['pasta' => '9999']), null, $this->eu, $this->tenant);
    }

    #[TestDox('Editar compromisso que não vejo dá "não encontrado"')]
    public function testEditarInvisivel(): void
    {
        $this->eventos->method('visivelPara')->willReturn(null);
        $this->expectException(CompromissoNaoEncontradoException::class);

        $this->sut()->executar($this->input([]), 5, $this->eu, $this->tenant);
    }

    #[TestDox('Editar compromisso de outro, sem administrar usuários, é recusado; administrador pode')]
    public function testEditarDeOutro(): void
    {
        $evento = (new Evento())->setCriador($this->maria)->setTenant($this->tenant);
        $this->eventos->method('visivelPara')->willReturn($evento);
        $this->permissoes->method('canAdminister')->willReturnOnConsecutiveCalls(false, true);

        try {
            $this->sut()->executar($this->input([]), 5, $this->eu, $this->tenant);
            self::fail('Devia recusar');
        } catch (SemPermissaoNoCompromissoException) {
        }
        $this->sut()->executar($this->input(['titulo' => 'Pelo administrador']), 5, $this->eu, $this->tenant);
        self::assertSame('Pelo administrador', $evento->getTitulo());
    }

    #[TestDox('Avisa no Chat só os colegas novos, nunca a si mesmo, e uma vez só')]
    public function testAvisaUmaVez(): void
    {
        $evento = (new Evento())->setCriador($this->eu)->setTenant($this->tenant);
        $this->eventos->method('visivelPara')->willReturn($evento);
        $this->notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($this->maria, $this->tenant, self::anything(), self::stringContains('Samuel marcou você em um compromisso: Audiência'), '/agenda#ev=ev--2030-03-10');

        $r1 = $this->sut()->executar($this->input(['avisados' => [1, 2, 99]]), 5, $this->eu, $this->tenant);
        $r2 = $this->sut()->executar($this->input(['avisados' => [1, 2]]), 5, $this->eu, $this->tenant);

        self::assertSame(1, $r1->avisados);
        self::assertSame(0, $r2->avisados);
        self::assertSame([1, 2], $evento->getAvisados());
    }

    #[TestDox('Aviso com título, pasta e observação longos cabe nos 255 caracteres da notificação')]
    public function testAvisoCabeNaNotificacao(): void
    {
        $evento = (new Evento())->setCriador($this->eu)->setTenant($this->tenant);
        $this->eventos->method('visivelPara')->willReturn($evento);
        $this->pastas->method('findDoEscritorioPorNup')->willReturn((new Pasta())->setNup('0001234-56.2026.8.02.0001'));
        $this->notificacoes->expects(self::once())->method('criarNotificacao')
            ->with(self::anything(), self::anything(), self::anything(), self::callback(static fn (string $m): bool => mb_strlen($m) <= 255 && str_ends_with($m, '…')));

        $this->sut()->executar($this->input(['titulo' => str_repeat('Título comprido ', 15), 'pasta' => '0001234-56.2026.8.02.0001', 'observacao' => str_repeat('x', 200), 'avisados' => [2]]), 5, $this->eu, $this->tenant);
    }

    #[TestDox('Editar evento da agenda antiga guarda a cor da legenda; compromisso novo usa a cor do tipo')]
    public function testCorDaLegendaAntiga(): void
    {
        $antigo = (new Evento())->setCriador($this->eu)->setTenant($this->tenant)->setCor('#f56954');
        (new \ReflectionProperty(Evento::class, 'id'))->setValue($antigo, 9);
        $this->eventos->method('visivelPara')->willReturn($antigo);

        $this->sut()->executar($this->input(['tipo' => 'reun']), 9, $this->eu, $this->tenant);
        $this->sut()->executar($this->input(['tipo' => 'aud', 'titulo' => 'Segunda edição']), 9, $this->eu, $this->tenant);

        self::assertSame('#f56954', $antigo->getCor(), 'a legenda fica também na segunda edição');
        self::assertSame(TipoDeCompromisso::Audiencia, $antigo->getTipo());
    }

    #[TestDox('Quem não vê pastas, ao editar, não desfaz a ligação com a pasta')]
    public function testPastaFicaParaQuemNaoVe(): void
    {
        $pasta = (new Pasta())->setNup('1180');
        $evento = (new Evento())->setCriador($this->eu)->setTenant($this->tenant)->setPasta($pasta);
        $this->eventos->method('visivelPara')->willReturn($evento);
        $this->pastas->expects(self::never())->method('findDoEscritorioPorNup');

        $this->sut()->executar($this->input(['pasta' => '']), 5, $this->eu, $this->tenant, false);

        self::assertSame($pasta, $evento->getPasta());
    }

    #[TestDox('Participante desligado continua no compromisso ao editar; os ativos seguem o pedido')]
    public function testParticipanteDesligadoFica(): void
    {
        $desligado = $this->usuario(3, 'João Desligado');
        $evento = (new Evento())->setCriador($this->eu)->setTenant($this->tenant)->addParticipante($desligado)->addParticipante($this->maria);
        $this->eventos->method('visivelPara')->willReturn($evento);

        $this->sut()->executar($this->input(['participantes' => []]), 5, $this->eu, $this->tenant);

        self::assertSame([$desligado], array_values($evento->getParticipantes()->toArray()));
    }

    #[TestDox('Compromisso que passa da meia-noite ou dura vários dias termina no dia certo')]
    public function testVariosDias(): void
    {
        $salvo = null;
        $this->eventos->method('salvar')->willReturnCallback(static function (Evento $e) use (&$salvo): void {
            $salvo = $e;
        });

        $this->sut()->executar($this->input(['inicio' => '20:00', 'fim' => '02:00', 'dias' => 1]), null, $this->eu, $this->tenant);
        self::assertSame('2030-03-11 02:00', $salvo->getDataFim()->format('Y-m-d H:i'));

        $this->sut()->executar($this->input(['diaInteiro' => true, 'dias' => 2]), null, $this->eu, $this->tenant);
        self::assertSame('2030-03-12 23:59:59', $salvo->getDataFim()->format('Y-m-d H:i:s'));
    }

    #[TestDox('"Só eu" não avisa ninguém nem guarda avisados')]
    public function testSoEuNaoAvisa(): void
    {
        $salvo = null;
        $this->eventos->method('salvar')->willReturnCallback(static function (Evento $e) use (&$salvo): void {
            $salvo = $e;
        });
        $this->notificacoes->expects(self::never())->method('criarNotificacao');

        $r = $this->sut()->executar($this->input(['visibilidade' => 'privado', 'avisados' => [2], 'participantes' => [2]]), null, $this->eu, $this->tenant);

        self::assertSame(0, $r->avisados);
        self::assertSame([], $salvo->getAvisados());
    }

    #[TestDox('Repetição que termina antes do início é recusada')]
    public function testRepetirAteAntes(): void
    {
        $this->expectExceptionMessage('A repetição precisa terminar depois do início.');

        $this->sut()->executar($this->input(['repetir' => 'semanal', 'repetirAte' => '2030-03-01']), null, $this->eu, $this->tenant);
    }

    #[TestDox('Repetição semanal até uma data e participantes só do escritório')]
    public function testRepeticaoEParticipantes(): void
    {
        $salvo = null;
        $this->eventos->method('salvar')->willReturnCallback(static function (Evento $e) use (&$salvo): void {
            $salvo = $e;
        });

        $this->sut()->executar($this->input(['repetir' => 'semanal', 'repetirAte' => '2030-06-30', 'participantes' => [2, 77]]), null, $this->eu, $this->tenant);

        self::assertTrue($salvo->isRecorrente());
        self::assertSame('semanal', $salvo->getTipoRecorrencia());
        self::assertSame('2030-06-30 23:59:59', $salvo->getFimRecorrencia()->format('Y-m-d H:i:s'));
        self::assertSame([$this->maria], $salvo->getParticipantes()->toArray());
    }

    private function sut(): SalvarCompromissoUseCase
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/agenda');

        return new SalvarCompromissoUseCase($this->eventos, $this->pastas, $this->usuarios, $this->permissoes, new AvisoDoCompromisso($this->notificacoes, $urls));
    }

    /** @param array<string, mixed> $sobre */
    private function input(array $sobre): CompromissoInput
    {
        return CompromissoInput::doJson(array_merge([
            'titulo' => 'Audiência', 'tipo' => 'meus', 'data' => '2030-03-10', 'diaInteiro' => false, 'inicio' => '09:00', 'fim' => '10:00',
            'visibilidade' => 'equipe', 'pasta' => '', 'lembreteMinutos' => 15, 'observacao' => '',
        ], $sobre));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
