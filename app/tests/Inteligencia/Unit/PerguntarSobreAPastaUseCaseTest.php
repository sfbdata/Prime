<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\PerguntarSobreAPastaInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Inteligencia\UseCase\PerguntarSobreAPastaUseCase;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AbaOcultaDaPastaRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\EquipeQuePodeTerAbaOculta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use App\Tests\Inteligencia\Support\TravaDaConversaEspia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * O pedido da aba "Perguntar" (spec inteligencia-drawer-da-pasta, F3) sem banco: mocks só dos nossos
 * repositórios e serviços; o montador do contexto é o de verdade, sobre fontes falsas.
 */
#[CoversClass(PerguntarSobreAPastaUseCase::class)]
#[CoversClass(PerguntarSobreAPastaInput::class)]
final class PerguntarSobreAPastaUseCaseTest extends TestCase
{
    private const PASTA_ID = 7;

    private Tenant $tenant;
    private User $user;
    private Pasta $pasta;
    private Processo $processo;
    private PastaRepository&MockObject $pastas;
    private PermissionChecker&MockObject $permissoes;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private PerguntaDaConversaRepository&MockObject $perguntas;
    private VisibilidadeDoFinanceiroDaPasta&MockObject $financeiro;
    private FonteDeMovimentacoesFalsa $movimentacoes;

    /** @var list<AbaOcultaDaPasta> */
    private array $regrasDeAba = [];

    /** @var list<object> */
    private array $despachadas = [];

    private TravaDaConversaEspia $trava;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->user = new User();
        DefineId::em($this->user, 9);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso('07011345720258070007');
        DefineId::em($this->processo, 3);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('1234');
        $this->pasta->vincularProcesso($this->processo);
        DefineId::em($this->pasta, self::PASTA_ID);

        $this->pastas = $this->createMock(PastaRepository::class);
        $this->pastas->method('findOneBy')->willReturnCallback(
            fn (array $criterios): ?Pasta => ($criterios['id'] ?? null) === self::PASTA_ID ? $this->pasta : null,
        );
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessResource')->willReturn(true);
        $this->permissoes->method('canAccessModule')->willReturn(true);

        $configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $configuracao->atualizar(true, 50, 500, true, $this->user);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturn($configuracao);

        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->analises->method('contarDesde')->willReturn(0);
        $this->analises->method('emAberto')->willReturn(true);
        $this->perguntas = $this->createMock(PerguntaDaConversaRepository::class);
        $this->financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $this->financeiro->method('podeVer')->willReturn(true);

        $this->movimentacoes = new FonteDeMovimentacoesFalsa();
        $this->movimentacoes->publicacoes = [$this->publicacao()];
        $this->despachadas = [];
        $this->regrasDeAba = [];
        $this->trava = new TravaDaConversaEspia();
    }

    private function publicacao(): PublicacaoDjen
    {
        $p = new PublicacaoDjen();
        $p->setTenant($this->tenant);
        $p->setDjenId('11');
        $p->setNumeroProcesso('07011345720258070007');
        $p->setSiglaTribunal('TJDFT');
        $p->setTipoComunicacao('Intimação');
        $p->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-01'));
        $p->setTexto('Intime-se a parte autora para réplica.');
        DefineId::em($p, 11);

        return $p;
    }

    /** @internal usado pelo dublê do bus */
    public function registrarDispatch(object $mensagem): void
    {
        self::assertFalse($this->trava->dentro, 'a mensagem só sai depois do commit, fora da trava');
        $this->despachadas[] = $mensagem;
    }

    private function sut(ProvedorDeLinguagem $provedor = new ProvedorFalso()): PerguntarSobreAPastaUseCase
    {
        $mascarador = new MascaradorDeDadosPessoais();
        $montador = new MontadorDeContextoDaPasta(
            new FonteDeDadosDaPastaFalsa(),
            $this->movimentacoes,
            new MontadorDeContextoDoPush($this->movimentacoes, $this->configuracoes, $mascarador),
            $this->configuracoes,
            $mascarador,
            new MockClock(),
        );
        $bus = new class ($this) implements MessageBusInterface {
            public function __construct(private readonly PerguntarSobreAPastaUseCaseTest $teste)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->teste->registrarDispatch($message);

                return new Envelope($message, $stamps);
            }
        };

        return new PerguntarSobreAPastaUseCase(
            $this->pastas,
            $this->permissoes,
            new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, true),
            $this->financeiro,
            $montador,
            $this->analises,
            $this->perguntas,
            new EnfileiradorDeAnalise($this->analises, $bus, new NullLogger()),
            $this->visibilidadeDasAbas(),
            $this->trava,
        );
    }

    private function visibilidadeDasAbas(): VisibilidadeDasAbasDaPasta
    {
        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturn($this->regrasDeAba);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);

        return new VisibilidadeDasAbasDaPasta($regras, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios));
    }

    private function input(string $texto = 'O que falta fazer?', int $pastaId = self::PASTA_ID): PerguntarSobreAPastaInput
    {
        return new PerguntarSobreAPastaInput($pastaId, $texto);
    }

    /** O que o UseCase gravou: a análise (sem flush) e a pergunta (com flush). */
    private function capturarSalvas(): \ArrayObject
    {
        $salvas = new \ArrayObject(['analises' => [], 'perguntas' => []]);
        $this->analises->method('salvar')->willReturnCallback(static function (AnaliseDeInteligencia $a) use ($salvas): void {
            DefineId::em($a, 555);
            $salvas['analises'] = [...$salvas['analises'], $a];
        });
        $this->perguntas->method('salvar')->willReturnCallback(static function (PerguntaDaConversa $p, bool $flush) use ($salvas): void {
            DefineId::em($p, 77);
            $salvas['perguntas'] = [...$salvas['perguntas'], [$p, $flush]];
        });

        return $salvas;
    }

    private function contador(): object
    {
        return new class () {
            public int $chamadas = 0;

            public function __invoke(): void
            {
                ++$this->chamadas;
            }
        };
    }

    // ---------------------------------------------------------------------------------------
    // Input
    // ---------------------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function textosInvalidos(): iterable
    {
        yield 'vazio' => [''];
        yield 'só espaços e quebras' => ["  \n\t "];
        yield '2001 caracteres' => [str_repeat('a', 2001)];
        yield '2001 com acento' => [str_repeat('ç', 2001)];
    }

    #[DataProvider('textosInvalidos')]
    #[TestDox('input recusa texto $_dataName com PerguntaInvalidaException')]
    public function testInputInvalido(string $texto): void
    {
        $this->expectException(PerguntaInvalidaException::class);

        new PerguntarSobreAPastaInput(self::PASTA_ID, $texto);
    }

    #[TestDox('input apara o texto e aceita 2000 caracteres (contados como caracteres, não bytes)')]
    public function testInputValido(): void
    {
        self::assertSame('Qual o prazo?', (new PerguntarSobreAPastaInput(1, "  Qual o prazo?\n"))->texto);
        self::assertSame(2000, mb_strlen((new PerguntarSobreAPastaInput(1, ' ' . str_repeat('ç', 2000) . ' '))->texto));
    }

    // ---------------------------------------------------------------------------------------
    // Guardas
    // ---------------------------------------------------------------------------------------

    #[TestDox('pasta inexistente ou de outro escritório → PastaNaoEncontradaException')]
    public function testPastaNaoEncontrada(): void
    {
        $this->expectException(PastaNaoEncontradaException::class);

        $this->sut()->executar($this->input(pastaId: 999), $this->user, $this->tenant);
    }

    #[TestDox('sem permissão de ver a pasta → AccessDeniedException, nada consultado da conversa')]
    public function testSemPermissao(): void
    {
        $this->permissoes = $this->createMock(PermissionChecker::class);
        $this->permissoes->method('canAccessResource')->willReturn(false);
        $this->perguntas->expects($this->never())->method('emAndamentoDaConversa');

        $this->expectException(AccessDeniedException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }

    #[TestDox('IA indisponível → InteligenciaIndisponivelException com o motivo; nada gravado nem enfileirado')]
    public function testIndisponivel(): void
    {
        $this->analises->expects($this->never())->method('salvar');
        $this->perguntas->expects($this->never())->method('salvar');

        try {
            $this->sut(new ProvedorNaoConfigurado())->executar($this->input(), $this->user, $this->tenant);
            self::fail('deveria ter lançado');
        } catch (InteligenciaIndisponivelException $e) {
            self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $e->motivo);
        }
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('sigilo → ContextoBloqueadoException antes do limitador; nada gravado')]
    public function testSigilo(): void
    {
        $this->processo->setNivelSigilo(1);
        $this->perguntas->method('emAndamentoDaConversa')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');
        $limitador = $this->contador();

        try {
            $this->sut()->executar($this->input(), $this->user, $this->tenant, $limitador);
            self::fail('deveria ter lançado');
        } catch (ContextoBloqueadoException) {
        }
        self::assertSame(0, $limitador->chamadas);
    }

    // ---------------------------------------------------------------------------------------
    // Idempotência e limitador
    // ---------------------------------------------------------------------------------------

    #[TestDox('pergunta da pessoa em andamento na pasta → devolve a mesma com aviso, sem gravar, sem enfileirar e sem gastar o limitador')]
    public function testEmAndamentoDevolveAMesma(): void
    {
        $analise = new AnaliseDeInteligencia($this->tenant, $this->user, TipoDeAnalise::PerguntaLivre, AnaliseDeInteligencia::ALVO_PASTA, self::PASTA_ID, 'pergunta-v1', 'h', [], Agente::Gestor);
        DefineId::em($analise, 100);
        $existente = new PerguntaDaConversa($this->tenant, self::PASTA_ID, $this->user, 'Primeira', $analise);
        DefineId::em($existente, 50);
        $this->perguntas->method('emAndamentoDaConversa')->willReturnCallback(
            fn (Tenant $t, int $pastaId, User $u): ?PerguntaDaConversa => $t === $this->tenant && $pastaId === self::PASTA_ID && $u === $this->user ? $existente : null,
        );
        $this->analises->expects($this->never())->method('salvar');
        $this->perguntas->expects($this->never())->method('salvar');
        $limitador = $this->contador();

        $saida = $this->sut()->executar($this->input('Segunda'), $this->user, $this->tenant, $limitador);

        self::assertSame(100, $saida->analise->id);
        self::assertSame(50, $saida->pergunta->perguntaId);
        self::assertSame('Primeira', $saida->pergunta->texto);
        self::assertSame(PerguntarSobreAPastaUseCase::AVISO_EM_ANDAMENTO, $saida->analise->aviso);
        self::assertSame(0, $limitador->chamadas);
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('limitador recusa ao criar → MuitasSolicitacoesException e nada gravado')]
    public function testLimitadorRecusa(): void
    {
        $this->perguntas->method('emAndamentoDaConversa')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');
        $this->perguntas->expects($this->never())->method('salvar');

        $this->expectException(MuitasSolicitacoesException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant, static function (): void {
            throw new MuitasSolicitacoesException();
        });
    }

    // ---------------------------------------------------------------------------------------
    // Caminho feliz
    // ---------------------------------------------------------------------------------------

    #[TestDox('cria: análise pergunta_livre do Gestor pergunta-v1 com financeiro e abas ocultas no resumo, pergunta com o texto, um flush, uma mensagem e o limitador uma vez')]
    public function testCria(): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Tarefas, $this->user, new \DateTimeImmutable())];
        $this->perguntas->method('emAndamentoDaConversa')->willReturn(null);
        $salvas = $this->capturarSalvas();
        $limitador = $this->contador();

        $saida = $this->sut()->executar($this->input('  Esse processo está parado?  '), $this->user, $this->tenant, $limitador);

        self::assertSame(1, $limitador->chamadas);
        self::assertCount(1, $salvas['analises']);
        $analise = $salvas['analises'][0];
        self::assertSame(TipoDeAnalise::PerguntaLivre, $analise->getTipo());
        self::assertSame(Agente::Gestor, $analise->getAgente());
        self::assertNull($analise->getComando());
        self::assertSame('pergunta-v1', $analise->getVersaoDoPrompt());
        self::assertSame(self::PASTA_ID, $analise->getAlvoId());
        self::assertSame($this->user, $analise->getSolicitante());
        self::assertTrue($analise->getContextoResumo()['financeiro']);
        self::assertSame(['tarefas'], $analise->getContextoResumo()['abasOcultas']);

        self::assertCount(1, $salvas['perguntas']);
        [$pergunta, $flush] = $salvas['perguntas'][0];
        self::assertTrue($flush, 'análise e pergunta no mesmo flush');
        self::assertSame('Esse processo está parado?', $pergunta->getTexto());
        self::assertSame($analise, $pergunta->getAnalise());
        self::assertSame($this->user, $pergunta->getAutor());
        self::assertSame(self::PASTA_ID, $pergunta->getPastaId());

        self::assertCount(1, $this->despachadas);
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $this->despachadas[0]);
        self::assertSame(555, $this->despachadas[0]->analiseId);
        self::assertSame(42, $this->despachadas[0]->tenantId);
        self::assertTrue($saida->emAndamento());
        self::assertSame(77, $saida->paraJson()['pergunta']);
        self::assertNull($saida->analise->aviso);
    }

    #[TestDox('sem permissão de ver o financeiro: a decisão (false) fica gravada no resumo')]
    public function testFinanceiroDecididoNaSolicitacao(): void
    {
        $this->financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $this->financeiro->method('podeVer')->willReturn(false);
        $this->perguntas->method('emAndamentoDaConversa')->willReturn(null);
        $salvas = $this->capturarSalvas();

        $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertFalse($salvas['analises'][0]->getContextoResumo()['financeiro']);
    }

    #[TestDox('pasta sem nenhuma linha nas seções: a pergunta é criada mesmo assim (não é sem_dados)')]
    public function testPastaSemDadosCria(): void
    {
        $this->movimentacoes->publicacoes = [];
        $this->perguntas->method('emAndamentoDaConversa')->willReturn(null);
        $salvas = $this->capturarSalvas();

        $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertCount(1, $salvas['perguntas']);
        self::assertCount(1, $this->despachadas);
    }

    // ---------------------------------------------------------------------------------------
    // Trava da conversa (concorrência)
    // ---------------------------------------------------------------------------------------

    #[TestDox('trava pedida por escritório, pasta e pessoa; checagem, limitador e gravação acontecem DENTRO dela')]
    public function testTudoDentroDaTrava(): void
    {
        $this->perguntas->method('emAndamentoDaConversa')->willReturnCallback(function (): ?PerguntaDaConversa {
            self::assertTrue($this->trava->dentro, 'a checagem de em andamento roda dentro da trava');

            return null;
        });
        $this->analises->method('salvar')->willReturnCallback(function (AnaliseDeInteligencia $a): void {
            self::assertTrue($this->trava->dentro);
            DefineId::em($a, 555);
        });
        $this->perguntas->method('salvar')->willReturnCallback(function (PerguntaDaConversa $p): void {
            self::assertTrue($this->trava->dentro, 'a gravação roda dentro da trava');
            DefineId::em($p, 77);
        });
        $limitadorDentro = null;

        $this->sut()->executar($this->input(), $this->user, $this->tenant, function () use (&$limitadorDentro): void {
            $limitadorDentro = $this->trava->dentro;
        });

        self::assertSame([[42, self::PASTA_ID, 9]], $this->trava->pedidos);
        self::assertTrue($limitadorDentro, 'o limitador roda dentro da trava');
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('dois pedidos em sequência sob a trava: o segundo vê a pergunta do primeiro e devolve a mesma, sem criar nem gastar o limitador')]
    public function testSegundoPedidoVeOPrimeiro(): void
    {
        $gravada = null;
        $this->perguntas->method('emAndamentoDaConversa')->willReturnCallback(static function () use (&$gravada): ?PerguntaDaConversa {
            return $gravada;
        });
        $this->analises->method('salvar')->willReturnCallback(static fn (AnaliseDeInteligencia $a) => DefineId::em($a, 555));
        $this->perguntas->method('salvar')->willReturnCallback(static function (PerguntaDaConversa $p) use (&$gravada): void {
            DefineId::em($p, 77);
            $gravada = $p;
        });
        $limitador = $this->contador();

        $primeira = $this->sut()->executar($this->input('Primeira'), $this->user, $this->tenant, $limitador);
        $segunda = $this->sut()->executar($this->input('Segunda'), $this->user, $this->tenant, $limitador);

        self::assertSame(77, $primeira->pergunta->perguntaId);
        self::assertSame(77, $segunda->pergunta->perguntaId);
        self::assertSame(PerguntarSobreAPastaUseCase::AVISO_EM_ANDAMENTO, $segunda->analise->aviso);
        self::assertSame(1, $limitador->chamadas);
        self::assertCount(1, $this->despachadas);
        self::assertCount(2, $this->trava->pedidos);
    }
}
