<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\DTO\SolicitarAnaliseDaPastaInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Inteligencia\UseCase\SolicitarAnaliseDaPastaUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(SolicitarAnaliseDaPastaUseCase::class)]
#[CoversClass(EnfileiradorDeAnalise::class)]
final class SolicitarAnaliseDaPastaUseCaseTest extends TestCase
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
    private VisibilidadeDoFinanceiroDaPasta&MockObject $financeiro;
    private FonteDeMovimentacoesFalsa $movimentacoes;
    private FonteDeDadosDaPastaFalsa $dados;

    /** @var list<object> */
    private array $despachadas = [];
    private ?\Throwable $falhaDoBus = null;
    private MessageBusInterface $bus;

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

        $this->financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $this->financeiro->method('podeVer')->willReturn(true);

        $this->movimentacoes = new FonteDeMovimentacoesFalsa();
        $p = new PublicacaoDjen();
        $p->setTenant($this->tenant);
        $p->setDjenId('11');
        $p->setNumeroProcesso('07011345720258070007');
        $p->setSiglaTribunal('TJDFT');
        $p->setTipoComunicacao('Intimação');
        $p->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-01'));
        $p->setTexto('Intime-se a parte autora para réplica.');
        DefineId::em($p, 11);
        $this->movimentacoes->publicacoes = [$p];

        $this->dados = new FonteDeDadosDaPastaFalsa();

        $this->despachadas = [];
        $this->falhaDoBus = null;
        $this->bus = new class($this) implements MessageBusInterface {
            public function __construct(private readonly SolicitarAnaliseDaPastaUseCaseTest $teste)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->teste->registrarDispatch($message);

                return new Envelope($message, $stamps);
            }
        };
    }

    /** @internal usado pelo dublê do bus */
    public function registrarDispatch(object $mensagem): void
    {
        if ($this->falhaDoBus !== null) {
            throw $this->falhaDoBus;
        }
        $this->despachadas[] = $mensagem;
    }

    private function sut(ProvedorDeLinguagem $provedor = new ProvedorFalso()): SolicitarAnaliseDaPastaUseCase
    {
        $mascarador = new MascaradorDeDadosPessoais();
        $montador = new MontadorDeContextoDaPasta(
            $this->dados,
            $this->movimentacoes,
            new MontadorDeContextoDoPush($this->movimentacoes, $this->configuracoes, $mascarador),
            $this->configuracoes,
            $mascarador,
        );

        return new SolicitarAnaliseDaPastaUseCase(
            $this->pastas,
            $this->permissoes,
            new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, true),
            $this->financeiro,
            $montador,
            $this->analises,
            new EnfileiradorDeAnalise($this->analises, $this->bus, new NullLogger()),
        );
    }

    private function input(Agente $agente = Agente::Gestor, int $pastaId = self::PASTA_ID): SolicitarAnaliseDaPastaInput
    {
        return new SolicitarAnaliseDaPastaInput($pastaId, $agente);
    }

    private function analiseExistente(Agente $agente, string $hash, bool $concluida): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia($this->tenant, $this->user, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, self::PASTA_ID, 'agente-v1', $hash, ['total' => 1], $agente);
        DefineId::em($a, 100);
        if ($concluida) {
            $a->iniciarProcessamento();
            $a->concluir('Resumo anterior.', [], null, '{}', 'falso', 'falso-1', 1, 1, 1, 'TEXTO');
        }

        return $a;
    }

    /** O que o UseCase gravou (via `salvar`), para as asserções do caminho feliz. */
    private function capturarSalva(): \ArrayObject
    {
        $salvas = new \ArrayObject();
        $this->analises->method('salvar')->willReturnCallback(static function (AnaliseDeInteligencia $a) use ($salvas): void {
            DefineId::em($a, 555);
            $salvas[] = $a;
        });

        return $salvas;
    }

    #[TestDox('indisponível (sem provedor) → InteligenciaIndisponivelException com o motivo e NADA persistido nem enfileirado')]
    public function testIndisponivelNaoPersiste(): void
    {
        $this->analises->expects($this->never())->method('salvar');

        try {
            $this->sut(new ProvedorNaoConfigurado())->executar($this->input(), $this->user, $this->tenant);
            self::fail('deveria ter lançado');
        } catch (InteligenciaIndisponivelException $e) {
            self::assertSame(Disponibilidade::NaoConfiguradaNaPlataforma, $e->motivo);
        }

        self::assertSame([], $this->despachadas);
    }

    #[TestDox('pasta inexistente ou de outro escritório → PastaNaoEncontradaException')]
    public function testPastaNaoEncontrada(): void
    {
        $this->expectException(PastaNaoEncontradaException::class);

        $this->sut()->executar($this->input(Agente::Gestor, 999), $this->user, $this->tenant);
    }

    #[TestDox('sem permissão de ver a pasta → AccessDeniedException, antes de olhar a disponibilidade')]
    public function testSemPermissaoNaPasta(): void
    {
        $permissoes = $this->createMock(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturn(false);
        $permissoes->expects($this->never())->method('canAccessModule');
        $this->permissoes = $permissoes;

        $this->expectException(AccessDeniedException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }

    #[TestDox('análise pendente do MESMO agente → devolve a mesma com aviso, sem novo dispatch')]
    public function testPendenteDoMesmoAgente(): void
    {
        $pendente = $this->analiseExistente(Agente::Prazos, 'h', false);
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static fn (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente): ?AnaliseDeInteligencia => $agente === Agente::Prazos && $tipo === TipoDeAnalise::AnalisePasta ? $pendente : null,
        );
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar($this->input(Agente::Prazos), $this->user, $this->tenant);

        self::assertSame(100, $saida->id);
        self::assertSame('prazos', $saida->agente);
        self::assertStringContainsString('Agente Prazos', (string) $saida->aviso);
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('análise pendente de OUTRO agente não trava este: o Gestor segue e enfileira')]
    public function testPendenteDeOutroAgenteNaoInterfere(): void
    {
        $pendenteDePrazos = $this->analiseExistente(Agente::Prazos, 'h', false);
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static fn (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente): ?AnaliseDeInteligencia => $agente === Agente::Prazos ? $pendenteDePrazos : null,
        );
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->input(Agente::Gestor), $this->user, $this->tenant);

        self::assertCount(1, $salvas);
        self::assertSame('gestor', $saida->agente);
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('contexto igual ao da última concluída do agente → devolve a última com aviso "nada novo", sem gastar cota')]
    public function testNadaNovoDesdeAUltima(): void
    {
        // Descobre o hash que o montador calcula hoje, criando primeiro sem "última".
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();
        $primeiro = $this->sut()->executar($this->input(), $this->user, $this->tenant);
        $hash = $salvas[0]->getContextoHash();

        $this->setUp();
        $ultima = $this->analiseExistente(Agente::Gestor, $hash, true);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn($ultima);
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertSame(100, $saida->id);
        self::assertSame('Nada novo desde a última análise deste agente.', $saida->aviso);
        self::assertSame('TEXTO', $saida->textoDaAnalise);
        self::assertSame([], $this->despachadas);
        self::assertSame(555, $primeiro->id);
    }

    #[TestDox('caminho feliz: linha pendente com tipo analise_pasta, agente, versão agente-v1, resumo só de contagens, financeiro=true e UMA mensagem')]
    public function testCaminhoFeliz(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->input(Agente::Gestor), $this->user, $this->tenant);

        self::assertCount(1, $salvas);
        /** @var AnaliseDeInteligencia $gravada */
        $gravada = $salvas[0];
        self::assertSame(TipoDeAnalise::AnalisePasta, $gravada->getTipo());
        self::assertSame(Agente::Gestor, $gravada->getAgente());
        self::assertSame(PromptDoAgente::VERSAO, $gravada->getVersaoDoPrompt());
        self::assertSame(StatusDaAnalise::Pendente, $gravada->getStatus());
        self::assertSame($this->user, $gravada->getSolicitante());
        $resumo = $gravada->getContextoResumo();
        self::assertSame('gestor', $resumo['agente']);
        self::assertTrue($resumo['financeiro']);
        // 1 movimentação + a linha fixa do financeiro (contrato/pró-bono/valor da causa).
        self::assertSame(1, $resumo['secoes']['movimentacoes']);
        self::assertSame(1, $resumo['secoes']['financeiro']);
        self::assertSame(2, $resumo['total']);
        self::assertStringNotContainsString('réplica', (string) json_encode($resumo), 'nunca o texto (D5)');

        self::assertSame('pendente', $saida->status);
        self::assertSame('gestor', $saida->agente);
        self::assertTrue($saida->emAndamento);

        self::assertCount(1, $this->despachadas);
        $mensagem = $this->despachadas[0];
        self::assertInstanceOf(ProcessarAnaliseDeInteligencia::class, $mensagem);
        self::assertSame(555, $mensagem->analiseId);
        self::assertSame(42, $mensagem->tenantId);
    }

    #[TestDox('quem NÃO pode ver o financeiro: a decisão fica gravada como false e a seção não é montada')]
    public function testFinanceiroSemVisibilidadeFicaFora(): void
    {
        $this->financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $this->financeiro->method('podeVer')->willReturn(false);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar($this->input(Agente::Gestor), $this->user, $this->tenant);

        $resumo = $salvas[0]->getContextoResumo();
        self::assertFalse($resumo['financeiro']);
        self::assertArrayNotHasKey('financeiro', $resumo['secoes']);
    }

    #[TestDox('agente que não lê financeiro nem pergunta pela visibilidade')]
    public function testAgenteSemFinanceiroNaoConsultaVisibilidade(): void
    {
        $this->financeiro = $this->createMock(VisibilidadeDoFinanceiroDaPasta::class);
        $this->financeiro->expects($this->never())->method('podeVer');
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar($this->input(Agente::Processual), $this->user, $this->tenant);

        self::assertFalse($salvas[0]->getContextoResumo()['financeiro']);
    }

    #[TestDox('dispatch que lança com o EM aberto → a linha vira falhou e o chamador NÃO recebe exceção')]
    public function testDispatchFalhaViraFalhou(): void
    {
        $this->falhaDoBus = new \RuntimeException('fila fora do ar');
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->input(), $this->user, $this->tenant);

        self::assertSame('falhou', $saida->status);
        self::assertSame(StatusDaAnalise::Falhou, $salvas[0]->getStatus());
        self::assertStringContainsString('falha ao enfileirar', (string) $salvas[0]->getErroMotivo());
    }

    #[TestDox('agente sem nenhum dado na pasta → ContextoVazioException "sem_dados", nada persistido')]
    public function testSemDados(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');

        try {
            $this->sut()->executar($this->input(Agente::Documental), $this->user, $this->tenant);
            self::fail('deveria ter lançado');
        } catch (ContextoVazioException $e) {
            self::assertSame('sem_dados', $e->motivo());
            self::assertStringContainsString('este agente', $e->getMessage());
        }
    }

    #[TestDox('processo em segredo de justiça → ContextoBloqueadoException, nada persistido')]
    public function testSigiloBloqueia(): void
    {
        $this->processo->setNivelSigilo(2);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->expects($this->never())->method('salvar');

        $this->expectException(ContextoBloqueadoException::class);

        $this->sut()->executar($this->input(), $this->user, $this->tenant);
    }
}
