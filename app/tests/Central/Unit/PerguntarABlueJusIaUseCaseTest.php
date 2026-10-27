<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\DTO\PerguntaDaIaOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\UseCase\AbrirConversaComAIaUseCase;
use App\Central\UseCase\PerguntarABlueJusIaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\PlataformaDeIaNoTesteTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A pergunta ao BlueJus IA na Central (F3, PR 2b) sem banco: mocks só dos nossos repositórios; a disponibilidade, o
 * enfileirador e o "abrir conversa" são os de verdade. A ordem dos eventos fica num registro único.
 */
#[CoversClass(PerguntarABlueJusIaUseCase::class)]
final class PerguntarABlueJusIaUseCaseTest extends TestCase
{
    use PlataformaDeIaNoTesteTrait;

    private Tenant $tenant;
    private User $eu;
    private Conversa $conversa;
    private ConversaRepository&MockObject $conversas;
    private MensagemRepository&MockObject $mensagens;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private PermissionChecker&MockObject $permissoes;
    private ?AnaliseDeInteligencia $emAndamento = null;
    private bool $vinculoAtivo = true;
    private bool $filaFalha = false;

    /** @var list<string> */
    private array $eventos = [];

    /** @var list<Mensagem> */
    private array $mensagensSalvas = [];

    /** @var list<AnaliseDeInteligencia> */
    private array $analisesSalvas = [];

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->eu = new User();
        DefineId::em($this->eu, 9);
        $this->conversa = Conversa::comAIa($this->tenant, $this->eu, new \DateTimeImmutable('2026-10-27 09:00:00'));
        DefineId::em($this->conversa, 300);

        $this->conversas = $this->createMock(ConversaRepository::class);
        $this->conversas->method('daIaDe')->willReturn($this->conversa);
        $this->conversas->method('travar')->willReturnCallback(function (): void {
            $this->eventos[] = 'travar';
        });

        $this->mensagens = $this->createMock(MensagemRepository::class);
        $this->mensagens->method('salvar')->willReturnCallback(function (Mensagem $m): void {
            DefineId::em($m, 700 + count($this->mensagensSalvas));
            $this->mensagensSalvas[] = $m;
            $this->eventos[] = 'salvar_mensagem';
        });

        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->analises->method('contarDesde')->willReturn(0);
        $this->analises->method('emAberto')->willReturn(true);
        // Como o repositório de verdade: com `$criadaApos`, só vale a pendente criada a partir dele.
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(function (Tenant $t, string $alvoTipo, int $alvoId, TipoDeAnalise $tipo, mixed $agente = null, mixed $comando = null, ?\DateTimeImmutable $criadaApos = null): ?AnaliseDeInteligencia {
            if ($this->emAndamento === null || ($criadaApos !== null && $this->emAndamento->getCriadaEm() < $criadaApos)) {
                return null;
            }

            return $this->emAndamento;
        });
        $this->analises->method('salvar')->willReturnCallback(function (AnaliseDeInteligencia $a): void {
            if ($a->getId() === null) {
                DefineId::em($a, 500 + count($this->analisesSalvas));
            }
            $this->analisesSalvas[] = $a;
            $this->eventos[] = 'salvar_analise';
        });

        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->liberarPeloMaster();
        $configuracao->atualizar(true, 50, 500, true, $this->eu);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);

        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessModule')->willReturn(true);
    }

    private function sut(bool $plataformaLigada = true): PerguntarABlueJusIaUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback(fn (): bool => $this->vinculoAtivo);
        $acesso = new AcessoACentral($vinculos, $this->createStub(ParticipanteRepository::class), $this->createStub(UserRepository::class));

        $conexao = $this->createMock(Connection::class);
        $conexao->method('beginTransaction')->willReturnCallback(function (): void {
            $this->eventos[] = 'begin';
        });
        $conexao->method('commit')->willReturnCallback(function (): void {
            $this->eventos[] = 'commit';
        });
        $conexao->method('rollBack')->willReturnCallback(function (): bool {
            $this->eventos[] = 'rollback';

            return true;
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conexao);
        $em->method('flush')->willReturnCallback(function (): void {
            $this->eventos[] = 'flush';
        });

        $relogio = new MockClock('2026-10-27 10:00:00');
        $bus = new class ($this) implements MessageBusInterface {
            public function __construct(private readonly PerguntarABlueJusIaUseCaseTest $teste)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->teste->despachar($message);

                return new Envelope($message, $stamps);
            }
        };
        $disponibilidade = new DisponibilidadeDeInteligencia(new ProvedorFalso(), $this->configuracoes, $this->analises, $this->permissoes, true, $this->plataformaDeIa($plataformaLigada), new CustoDaIa('us'));

        return new PerguntarABlueJusIaUseCase(
            $acesso,
            $this->conversas,
            $this->mensagens,
            $this->analises,
            new AbrirConversaComAIaUseCase($acesso, $this->conversas, $this->createStub(ParticipanteRepository::class), $em, $relogio),
            $disponibilidade,
            new EnfileiradorDeAnalise($this->analises, $bus, new NullLogger()),
            $em,
            $relogio,
        );
    }

    /** @internal usado pelo dublê do bus */
    public function despachar(object $mensagem): void
    {
        $this->eventos[] = 'despachar';
        if ($this->filaFalha) {
            throw new TransportException('fila fora do ar');
        }
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
    }

    private function executar(string $texto = 'Qual o prazo da réplica?', ?callable $limitador = null): PerguntaDaIaOutput
    {
        return $this->sut()->executar($this->eu, $this->tenant, $texto, $limitador);
    }

    #[TestDox('sem vínculo ativo: 403 da Central, nada gravado nem despachado')]
    public function testSemVinculo(): void
    {
        $this->vinculoAtivo = false;

        try {
            $this->executar();
            self::fail('Devia recusar.');
        } catch (SemAcessoACentralException) {
            self::assertSame([], $this->eventos);
        }
    }

    #[TestDox('texto inválido: 422 antes de qualquer coisa, limitador não roda')]
    public function testTextoInvalido(): void
    {
        $chamado = false;

        try {
            $this->executar('   ', function () use (&$chamado): void {
                $chamado = true;
            });
            self::fail('Devia recusar.');
        } catch (PerguntaInvalidaException) {
            self::assertFalse($chamado);
            self::assertSame([], $this->eventos);
        }
    }

    #[TestDox('IA indisponível (plataforma desligada): recusa com o motivo, nada gravado')]
    public function testPlataformaDesligada(): void
    {
        try {
            $this->sut(plataformaLigada: false)->executar($this->eu, $this->tenant, 'Oi');
            self::fail('Devia recusar.');
        } catch (InteligenciaIndisponivelException $e) {
            self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $e->motivo);
            self::assertSame([], $this->eventos);
        }
    }

    #[TestDox('sem o módulo da IA: recusa, nada gravado')]
    public function testSemModulo(): void
    {
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessModule')->willReturn(false);

        $this->expectException(InteligenciaIndisponivelException::class);

        $this->executar();
    }

    #[TestDox('cota diária esgotada: recusa e não grava mensagem nem análise')]
    public function testCotaEsgotada(): void
    {
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->analises->method('contarDesde')->willReturn(50);

        try {
            $this->executar();
            self::fail('Devia recusar.');
        } catch (InteligenciaIndisponivelException $e) {
            self::assertSame([], $this->mensagensSalvas);
            self::assertSame([], $this->eventos);
            self::assertNotNull($e->motivo);
        }
    }

    #[TestDox('já em andamento: devolve a mesma, sem mensagem nova, sem limitador, sem fila')]
    public function testEmAndamento(): void
    {
        $existente = new AnaliseDeInteligencia($this->tenant, $this->eu, TipoDeAnalise::ChatDaCentral, AnaliseDeInteligencia::ALVO_CONVERSA_IA, 300, 'chat-central-v1', 'h', ['conversaId' => 300, 'mensagemId' => 650, 'historico' => 0, 'total' => 1]);
        DefineId::em($existente, 480);
        (new \ReflectionProperty(AnaliseDeInteligencia::class, 'criadaEm'))->setValue($existente, new \DateTimeImmutable('2026-10-27 09:55:00 UTC'));   // o relógio marca 10:00
        $this->emAndamento = $existente;
        $limitadorRodou = false;

        $saida = $this->executar('Outra pergunta', function () use (&$limitadorRodou): void {
            $limitadorRodou = true;
        });

        self::assertTrue($saida->emAndamento);
        self::assertSame(480, $saida->analiseId);
        self::assertSame(650, $saida->mensagemId);
        self::assertSame(300, $saida->conversaId);
        self::assertSame('pendente', $saida->status);
        self::assertFalse($limitadorRodou);
        self::assertSame([], $this->mensagensSalvas);
        self::assertSame(['begin', 'travar', 'commit'], $this->eventos);
    }

    #[TestDox('pendente presa há mais de 10 minutos não conta: encerra como falhou, grava a pergunta nova e enfileira (com o limitador)')]
    public function testPendentePresaNaoBloqueia(): void
    {
        $presa = new AnaliseDeInteligencia($this->tenant, $this->eu, TipoDeAnalise::ChatDaCentral, AnaliseDeInteligencia::ALVO_CONVERSA_IA, 300, 'chat-central-v1', 'h', ['conversaId' => 300, 'mensagemId' => 650, 'historico' => 0, 'total' => 1]);
        DefineId::em($presa, 480);
        // O relógio do teste marca 10:00; a análise nasceu às 09:45 (15 minutos atrás).
        (new \ReflectionProperty(AnaliseDeInteligencia::class, 'criadaEm'))->setValue($presa, new \DateTimeImmutable('2026-10-27 09:45:00 UTC'));
        $this->emAndamento = $presa;
        $limitadorRodou = false;

        $saida = $this->executar('Nova pergunta', function () use (&$limitadorRodou): void {
            $limitadorRodou = true;
        });

        self::assertFalse($saida->emAndamento, 'a pergunta presa não conta como em andamento');
        self::assertNotSame(480, $saida->analiseId);
        self::assertTrue($limitadorRodou, 'é pergunta nova: gasta o ritmo da pessoa');
        self::assertSame(StatusDaAnalise::Falhou, $presa->getStatus());
        self::assertSame(PerguntarABlueJusIaUseCase::MOTIVO_PRESA, $presa->getErroMotivo());
        self::assertCount(1, $this->mensagensSalvas);
        self::assertContains('despachar', $this->eventos);
    }

    #[TestDox('pendente de 9 minutos ainda é em andamento: devolve a mesma e não encerra nada')]
    public function testPendenteRecenteContaComoEmAndamento(): void
    {
        $recente = new AnaliseDeInteligencia($this->tenant, $this->eu, TipoDeAnalise::ChatDaCentral, AnaliseDeInteligencia::ALVO_CONVERSA_IA, 300, 'chat-central-v1', 'h', ['conversaId' => 300, 'mensagemId' => 650, 'historico' => 0, 'total' => 1]);
        DefineId::em($recente, 481);
        (new \ReflectionProperty(AnaliseDeInteligencia::class, 'criadaEm'))->setValue($recente, new \DateTimeImmutable('2026-10-27 09:51:00'));
        $this->emAndamento = $recente;

        $saida = $this->executar();

        self::assertTrue($saida->emAndamento);
        self::assertSame(481, $saida->analiseId);
        self::assertSame(StatusDaAnalise::Pendente, $recente->getStatus());
    }

    #[TestDox('limitador recusando: desfaz a transação, não grava nada e não enfileira')]
    public function testLimitadorRecusa(): void
    {
        try {
            $this->executar('Oi', static function (): void {
                throw new MuitasSolicitacoesException('limite');
            });
            self::fail('Devia recusar.');
        } catch (MuitasSolicitacoesException) {
            self::assertSame([], $this->mensagensSalvas);
            self::assertSame([], $this->analisesSalvas);
            self::assertSame(['begin', 'travar', 'rollback'], $this->eventos);
        }
    }

    #[TestDox('grava a mensagem normal (sem IA, sem menção/aviso/resposta) e a análise pendente com mensagemId, e só enfileira depois do commit')]
    public function testGrava(): void
    {
        $limitadorRodou = false;

        $saida = $this->executar('  Prazo de @[Fulano](user:1)?  ', function () use (&$limitadorRodou): void {
            $limitadorRodou = true;
        });

        self::assertTrue($limitadorRodou);
        self::assertFalse($saida->emAndamento);
        self::assertSame(['begin', 'travar', 'salvar_mensagem', 'flush', 'salvar_analise', 'flush', 'commit', 'despachar'], $this->eventos);

        self::assertCount(1, $this->mensagensSalvas);
        $mensagem = $this->mensagensSalvas[0];
        self::assertFalse($mensagem->isDeIa());
        self::assertFalse($mensagem->isSistema());
        self::assertSame('Prazo de @[Fulano](user:1)?', $mensagem->getConteudo());
        self::assertSame($this->eu, $mensagem->getAutor());
        self::assertNull($mensagem->getRespostaA());
        self::assertNotNull($this->conversa->getUltimaMensagemEm());

        self::assertCount(1, $this->analisesSalvas);
        $analise = $this->analisesSalvas[0];
        self::assertSame(TipoDeAnalise::ChatDaCentral, $analise->getTipo());
        self::assertSame(AnaliseDeInteligencia::ALVO_CONVERSA_IA, $analise->getAlvoTipo());
        self::assertSame(300, $analise->getAlvoId());
        self::assertSame($this->eu, $analise->getSolicitante());
        self::assertSame(StatusDaAnalise::Pendente, $analise->getStatus());
        self::assertSame(['conversaId' => 300, 'mensagemId' => (int) $mensagem->getId(), 'historico' => 0, 'total' => 1], $analise->getContextoResumo());
        self::assertSame(hash('sha256', '300|' . $mensagem->getId()), $analise->getContextoHash());
        self::assertStringNotContainsString('Fulano', (string) json_encode($analise->getContextoResumo()));

        self::assertSame((int) $analise->getId(), $saida->analiseId);
        self::assertSame((int) $mensagem->getId(), $saida->mensagemId);
        self::assertSame('pendente', $saida->status);
    }

    #[TestDox('fila fora do ar: a mensagem fica, a análise vira falhou e nenhuma resposta é fabricada')]
    public function testFilaFalha(): void
    {
        $this->filaFalha = true;

        $saida = $this->executar();

        self::assertSame('falhou', $saida->status);
        self::assertFalse($saida->emAndamento);
        self::assertCount(1, $this->mensagensSalvas);
        self::assertSame(StatusDaAnalise::Falhou, $this->analisesSalvas[0]->getStatus());
        self::assertNotContains('rollback', $this->eventos);
    }

    #[TestDox('conversa ainda não existe: abre a dela (idempotente) e segue')]
    public function testAbreAConversa(): void
    {
        $this->conversas = $this->createMock(ConversaRepository::class);
        $this->conversas->method('daIaDe')->willReturnOnConsecutiveCalls(null, null, $this->conversa);
        $this->conversas->expects(self::once())->method('salvar')->willReturnCallback(function (): void {
            $this->eventos[] = 'abrir';
        });

        $saida = $this->executar();

        self::assertSame(300, $saida->conversaId);
        self::assertSame('abrir', $this->eventos[0]);
    }
}
