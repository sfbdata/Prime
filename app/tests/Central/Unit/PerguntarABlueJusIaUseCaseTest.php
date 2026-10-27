<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\DTO\PerguntaDaIaOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\PastaDoContextoDaIa;
use App\Central\Service\PastasDosClientesDaIa;
use App\Central\UseCase\AbrirConversaComAIaUseCase;
use App\Central\UseCase\PerguntarABlueJusIaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\CruzamentoDePastasSemAceiteException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Processo\Entity\Processo;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
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
    private const PASTA_ID = 7;
    private const NUP_DO_PROCESSO = '07011345720258070007';

    private Pasta $pasta;
    private Processo $processo;
    private bool $podeVerPasta = true;
    private bool $podeVerFinanceiro = true;
    private PastasDosClientesDaIa&MockObject $pastasDosClientes;

    /** @var list<AbaOcultaDaPasta> */
    private array $regrasDeAba = [];

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

        $this->pastasDosClientes = $this->createMock(PastasDosClientesDaIa::class);
        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->liberarPeloMaster();
        $configuracao->atualizar(true, 50, 500, true, $this->eu);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);

        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessModule')->willReturn(true);
        $this->permissoes->method('canAccessResource')->willReturnCallback(fn (): bool => $this->podeVerPasta);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso(self::NUP_DO_PROCESSO);
        DefineId::em($this->processo, 3);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('1234');
        $this->pasta->vincularProcesso($this->processo);
        DefineId::em($this->pasta, self::PASTA_ID);
    }

    private function pastaDoContexto(): PastaDoContextoDaIa
    {
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->method('findOneBy')->willReturnCallback(
            fn (array $criterios): ?Pasta => ($criterios['id'] ?? null) === self::PASTA_ID && ($criterios['tenant'] ?? null)?->getId() === $this->pasta->getTenant()?->getId() ? $this->pasta : null,
        );
        $acessoAPasta = new AcessoAPasta((new \ReflectionClass(NivelNoCadeado::class))->newInstanceWithoutConstructor(), $this->permissoes);
        $financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(fn (): bool => $this->podeVerFinanceiro);

        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturn($this->regrasDeAba);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);
        $abas = new VisibilidadeDasAbasDaPasta($regras, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios));

        $mascarador = new MascaradorDeDadosPessoais();
        $movimentacoes = new FonteDeMovimentacoesFalsa();
        $montador = new MontadorDeContextoDaPasta(
            new FonteDeDadosDaPastaFalsa(),
            $movimentacoes,
            new MontadorDeContextoDoPush($movimentacoes, $this->configuracoes, $mascarador),
            $this->configuracoes,
            $mascarador,
            new MockClock(),
        );

        return new PastaDoContextoDaIa($pastas, $acessoAPasta, $financeiro, $abas, $montador);
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
            $this->pastaDoContexto(),
            $this->pastasDosClientes,
            $this->configuracoes,
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

    private function executar(string $texto = 'Qual o prazo da réplica?', ?callable $limitador = null, ?int $pastaId = null, ?string $escopo = null): PerguntaDaIaOutput
    {
        return $this->sut()->executar($this->eu, $this->tenant, $texto, $limitador, $pastaId, $escopo);
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

    // ---------------------------------------------------------------------------------------
    // Contexto da tela (PR 4a): pastaId
    // ---------------------------------------------------------------------------------------

    /** Recusa da pasta: nada começou. Sem begin (transação nunca aberta), sem trava, sem mensagem, sem análise, sem fila. */
    private function afirmarNadaFeito(): void
    {
        self::assertSame([], $this->eventos, 'a recusa sai antes da transação travada');
        self::assertSame([], $this->mensagensSalvas);
        self::assertSame([], $this->analisesSalvas);
    }

    /** @return iterable<string, array{int}> */
    public static function pastasInvalidas(): iterable
    {
        yield 'zero' => [0];
        yield 'negativo' => [-3];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pastasInvalidas')]
    #[TestDox('pastaId $_dataName: 422 antes de qualquer coisa, limitador não roda')]
    public function testPastaIdInvalido(int $pastaId): void
    {
        $chamado = false;

        try {
            $this->executar('Oi', function () use (&$chamado): void {
                $chamado = true;
            }, $pastaId);
            self::fail('Devia recusar.');
        } catch (PerguntaInvalidaException) {
            self::assertFalse($chamado);
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('pasta que não existe: 404 de domínio, fora da transação, nada gravado')]
    public function testPastaInexistente(): void
    {
        try {
            $this->executar('Oi', null, 999);
            self::fail('Devia recusar.');
        } catch (PastaDaIaNaoEncontradaException $e) {
            self::assertSame('Não encontrei essa pasta ou você não tem acesso.', $e->getMessage());
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('pasta de OUTRO escritório: o mesmo 404 (não confirma que existe), nada gravado')]
    public function testPastaDeOutroTenant(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);
        $this->pasta->setTenant($outro);

        try {
            $this->executar('Oi', null, self::PASTA_ID);
            self::fail('Devia recusar.');
        } catch (PastaDaIaNaoEncontradaException) {
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('pasta oculta pelo cadeado (AcessoAPasta::podeVer falso): o mesmo 404, nada gravado')]
    public function testPastaOcultaPeloCadeado(): void
    {
        $this->podeVerPasta = false;

        try {
            $this->executar('Oi', null, self::PASTA_ID);
            self::fail('Devia recusar.');
        } catch (PastaDaIaNaoEncontradaException $e) {
            self::assertStringNotContainsString('1234', $e->getMessage());
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('processo em segredo de justiça: contexto_bloqueado antes da transação, nada gravado, limitador não roda')]
    public function testSegredoDeJustica(): void
    {
        $this->processo->setNivelSigilo(1);
        $chamado = false;

        try {
            $this->executar('Oi', function () use (&$chamado): void {
                $chamado = true;
            }, self::PASTA_ID);
            self::fail('Devia recusar.');
        } catch (ContextoBloqueadoException $e) {
            self::assertSame('contexto_bloqueado', $e->motivo());
            self::assertFalse($chamado);
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('com pasta: grava SÓ pastaId, financeiro e abasOcultas (ids e flags), prompt v2, hash com a pasta; nunca NUP nem texto')]
    public function testGravaComPasta(): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Tarefas, $this->eu, new \DateTimeImmutable())];

        $saida = $this->executar('Resuma esta pasta', null, self::PASTA_ID);

        self::assertFalse($saida->emAndamento);
        $analise = $this->analisesSalvas[0];
        $mensagemId = (int) $this->mensagensSalvas[0]->getId();
        self::assertSame(
            ['conversaId' => 300, 'mensagemId' => $mensagemId, 'historico' => 0, 'total' => 1, 'pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => ['tarefas']],
            $analise->getContextoResumo(),
        );
        self::assertSame(PromptDoChatDaCentral::VERSAO_COM_PASTA, $analise->getVersaoDoPrompt());
        self::assertSame('chat-central-v2', $analise->getVersaoDoPrompt());
        self::assertSame(hash('sha256', '300|' . $mensagemId . '|pasta:' . self::PASTA_ID), $analise->getContextoHash());
        self::assertNotSame(hash('sha256', '300|' . $mensagemId), $analise->getContextoHash(), 'o hash inclui a pasta');
        $json = (string) json_encode($analise->getContextoResumo());
        self::assertStringNotContainsString(self::NUP_DO_PROCESSO, $json);
        self::assertStringNotContainsString('1234', $json);
        self::assertStringNotContainsString('Resuma', $json);
        self::assertContains('despachar', $this->eventos);
    }

    #[TestDox('sem permissão do financeiro: financeiro=false no resumo (a pasta segue liberada)')]
    public function testFinanceiroSemPermissao(): void
    {
        $this->podeVerFinanceiro = false;

        $this->executar('Oi', null, self::PASTA_ID);

        $resumo = $this->analisesSalvas[0]->getContextoResumo();
        self::assertFalse($resumo['financeiro']);
        self::assertSame([], $resumo['abasOcultas']);
    }

    #[TestDox('abas ocultas para a pessoa vão para o resumo como valores do enum (ids, não texto)')]
    public function testAbasOcultas(): void
    {
        $this->regrasDeAba = [
            AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Financeiro, $this->eu, new \DateTimeImmutable()),
            AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Tarefas, $this->eu, new \DateTimeImmutable()),
        ];

        $this->executar('Oi', null, self::PASTA_ID);

        $ocultas = $this->analisesSalvas[0]->getContextoResumo()['abasOcultas'];
        self::assertEqualsCanonicalizing(['financeiro', 'tarefas'], $ocultas);
    }

    #[TestDox('em andamento: devolve a mesma (202) e IGNORA o pastaId novo, sem tocar a pasta nem gravar')]
    public function testEmAndamentoIgnoraPastaId(): void
    {
        $existente = new AnaliseDeInteligencia($this->tenant, $this->eu, TipoDeAnalise::ChatDaCentral, AnaliseDeInteligencia::ALVO_CONVERSA_IA, 300, 'chat-central-v1', 'h', ['conversaId' => 300, 'mensagemId' => 650, 'historico' => 0, 'total' => 1]);
        DefineId::em($existente, 480);
        (new \ReflectionProperty(AnaliseDeInteligencia::class, 'criadaEm'))->setValue($existente, new \DateTimeImmutable('2026-10-27 09:55:00 UTC'));
        $this->emAndamento = $existente;
        $limitadorRodou = false;

        $saida = $this->executar('Outra', function () use (&$limitadorRodou): void {
            $limitadorRodou = true;
        }, self::PASTA_ID);

        self::assertTrue($saida->emAndamento);
        self::assertSame(480, $saida->analiseId);
        self::assertFalse($limitadorRodou);
        self::assertSame([], $this->mensagensSalvas);
        self::assertSame([], $this->analisesSalvas);
        self::assertSame(['conversaId' => 300, 'mensagemId' => 650, 'historico' => 0, 'total' => 1], $existente->getContextoResumo(), 'a análise existente não ganha a pasta nova');
        self::assertSame(['begin', 'travar', 'commit'], $this->eventos);
    }

    /** @return array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int} */
    private function adicionaisDe(int ...$ids): array
    {
        return ['pastas' => array_map(static fn (int $id): array => ['pastaId' => $id, 'financeiro' => false, 'abasOcultas' => ['tarefas']], $ids), 'naoConsultadas' => 2];
    }

    #[TestDox('escopo clientes: resumo com escopo, ids (aberta primeiro), decisão por pasta e contagem; prompt v3; hash com o escopo e as pastas; nunca NUP')]
    public function testGravaComClientes(): void
    {
        $this->pastasDosClientes->expects(self::once())->method('adicionais')->with($this->eu, $this->tenant, self::PASTA_ID)->willReturn($this->adicionaisDe(21, 22));

        $this->executar('Cruze as pastas', null, self::PASTA_ID, 'clientes');

        $analise = $this->analisesSalvas[0];
        $mensagemId = (int) $this->mensagensSalvas[0]->getId();
        $resumo = $analise->getContextoResumo();
        self::assertSame('clientes', $resumo['escopo']);
        self::assertSame([self::PASTA_ID, 21, 22], $resumo['pastasIds']);
        self::assertSame(2, $resumo['naoConsultadas']);
        self::assertSame(['pastaId' => 21, 'financeiro' => false, 'abasOcultas' => ['tarefas']], $resumo['pastas'][1]);
        self::assertSame(['pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []], $resumo['pastas'][0], 'a decisão da aberta não é herdada pelas outras');
        self::assertSame(self::PASTA_ID, $resumo['pastaId']);
        self::assertSame('chat-central-v3', $analise->getVersaoDoPrompt());
        self::assertSame(hash('sha256', '300|' . $mensagemId . '|pasta:' . self::PASTA_ID . '|clientes:7,21,22'), $analise->getContextoHash());
        $json = (string) json_encode($resumo);
        self::assertStringNotContainsString(self::NUP_DO_PROCESSO, $json);
        self::assertStringNotContainsString('Cruze', $json);
    }

    #[TestDox('escopo clientes sem aceite do escritório: 409 de domínio antes de pasta e transação, nada gravado, serviço não é chamado')]
    public function testClientesSemAceite(): void
    {
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $semAceite = new ConfiguracaoDeInteligencia($this->tenant);
        $semAceite->liberarPeloMaster();
        $semAceite->atualizar(true, 50, 500, true, $this->eu);
        (new \ReflectionProperty(ConfiguracaoDeInteligencia::class, 'consentimentoEnvioExternoEm'))->setValue($semAceite, null);
        $this->configuracoes->method('findDoTenant')->willReturn($semAceite);
        $this->pastasDosClientes->expects(self::never())->method('adicionais');

        try {
            $this->executar('Oi', null, self::PASTA_ID, 'clientes');
            self::fail('Devia recusar.');
        } catch (CruzamentoDePastasSemAceiteException $e) {
            self::assertSame('cruzamento_sem_consentimento', $e->motivo());
            $this->afirmarNadaFeito();
        }
    }

    #[TestDox('escopo clientes sem pastaId: 422, nada gravado')]
    public function testClientesSemPastaId(): void
    {
        $this->expectException(PerguntaInvalidaException::class);

        $this->executar('Oi', null, null, 'clientes');
    }

    #[TestDox('escopo "pasta": o serviço das outras pastas nem é chamado e o resumo fica como na 4a')]
    public function testEscopoPastaNaoChamaOServico(): void
    {
        $this->pastasDosClientes->expects(self::never())->method('adicionais');

        $this->executar('Oi', null, self::PASTA_ID, 'pasta');

        self::assertSame('chat-central-v2', $this->analisesSalvas[0]->getVersaoDoPrompt());
        self::assertArrayNotHasKey('escopo', $this->analisesSalvas[0]->getContextoResumo());
    }

    #[TestDox('escopo clientes com pasta aberta oculta (404) ou com segredo (409): recusa antes de procurar as outras pastas')]
    public function testClientesAbertaRecusada(): void
    {
        $this->pastasDosClientes->expects(self::never())->method('adicionais');
        $this->podeVerPasta = false;
        try {
            $this->executar('Oi', null, self::PASTA_ID, 'clientes');
            self::fail('Devia recusar.');
        } catch (PastaDaIaNaoEncontradaException) {
            $this->afirmarNadaFeito();
        }

        $this->podeVerPasta = true;
        $this->processo->setNivelSigilo(1);
        $this->expectException(ContextoBloqueadoException::class);
        $this->executar('Oi', null, self::PASTA_ID, 'clientes');
    }

    #[TestDox('sem pastaId: nada muda (resumo sem os campos novos, prompt v1, hash antigo)')]
    public function testSemPastaIdInalterado(): void
    {
        $this->podeVerPasta = false;   // sem pastaId a pasta nem é consultada

        $this->executar('Oi');

        $analise = $this->analisesSalvas[0];
        $mensagemId = (int) $this->mensagensSalvas[0]->getId();
        self::assertSame(['conversaId' => 300, 'mensagemId' => $mensagemId, 'historico' => 0, 'total' => 1], $analise->getContextoResumo());
        self::assertSame('chat-central-v1', $analise->getVersaoDoPrompt());
        self::assertSame(hash('sha256', '300|' . $mensagemId), $analise->getContextoHash());
    }
}
