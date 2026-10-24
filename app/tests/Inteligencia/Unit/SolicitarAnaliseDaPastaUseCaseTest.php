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
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AceiteDesatualizadoException;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Exception\SelecaoDeDocumentosInvalidaException;
use App\Inteligencia\Message\ProcessarAnaliseDeInteligencia;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Inteligencia\Service\RegraDaLeituraDeDocumentos;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Inteligencia\UseCase\SolicitarAnaliseDaPastaUseCase;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Tests\Inteligencia\Support\PlataformaDeIaNoTesteTrait;

#[CoversClass(SolicitarAnaliseDaPastaUseCase::class)]
#[CoversClass(EnfileiradorDeAnalise::class)]
final class SolicitarAnaliseDaPastaUseCaseTest extends TestCase
{
    use PlataformaDeIaNoTesteTrait;

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
        $configuracao->liberarPeloMaster();
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
            new MockClock(),
        );

        return new SolicitarAnaliseDaPastaUseCase(
            $this->pastas,
            $this->permissoes,
            new DisponibilidadeDeInteligencia($provedor, $this->configuracoes, $this->analises, $this->permissoes, true, $this->plataformaDeIa()),
            $this->financeiro,
            $montador,
            $this->analises,
            new EnfileiradorDeAnalise($this->analises, $this->bus, new NullLogger()),
            $this->visibilidadeDasAbas(),
            new RegraDaLeituraDeDocumentos($this->configuracoes),
        );
    }

    /** @var list<\App\Pasta\Entity\AbaOcultaDaPasta> regras de aba oculta da pasta (ocultar aba) */
    private array $regrasDeAba = [];

    private function visibilidadeDasAbas(): \App\Pasta\Service\VisibilidadeDasAbasDaPasta
    {
        $regras = $this->createStub(\App\Pasta\Repository\AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturn($this->regrasDeAba);
        $vinculos = $this->createStub(\App\Repository\UserTenantRepository::class);
        $primarios = new \App\Tenant\Service\RegraDosPrimarios($vinculos);

        return new \App\Pasta\Service\VisibilidadeDasAbasDaPasta($regras, $primarios, new \App\Pasta\Service\EquipeQuePodeTerAbaOculta($vinculos, $primarios));
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
        // 1 movimentação; o financeiro foi liberado mas a pasta não tem dado financeiro (contrato
        // PENDENTE padrão não conta), então a seção fica vazia.
        self::assertSame(1, $resumo['secoes']['movimentacoes']);
        self::assertSame(0, $resumo['secoes']['financeiro']);
        self::assertSame(1, $resumo['total']);
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

    #[TestDox('aba oculta para quem pede: a solicitação grava as abas ocultas e a seção delas não é montada')]
    public function testAbaOcultaFicaForaEGravada(): void
    {
        $this->regrasDeAba = [\App\Pasta\Entity\AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, \App\Pasta\Entity\AbaDaPasta::Tarefas, $this->user, new \DateTimeImmutable())];
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar($this->input(Agente::Gestor), $this->user, $this->tenant);

        $resumo = $salvas[0]->getContextoResumo();
        self::assertSame(['tarefas'], $resumo['abasOcultas']);
        self::assertArrayNotHasKey('metas', $resumo['secoes']);
        self::assertSame(1, $resumo['secoes']['movimentacoes']);
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

    // ---------------------------------------------------------------------------------------
    // Comandos do drawer (spec inteligencia-drawer-da-pasta, F1/F2)
    // ---------------------------------------------------------------------------------------

    private function analiseDoComando(Comando $comando, string $hash, bool $concluida): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia($this->tenant, $this->user, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, self::PASTA_ID, PromptDoAgente::VERSAO, $hash, ['total' => 1], $comando->agente(), $comando);
        DefineId::em($a, 200);
        if ($concluida) {
            $a->iniciarProcessamento();
            $a->concluir('Resumo do comando.', [], null, '{}', 'falso', 'falso-1', 1, 1, 1, 'TEXTO DO COMANDO');
        }

        return $a;
    }

    #[TestDox('comando: o agente é o do comando, a linha grava o comando e o resumo do contexto o leva')]
    public function testComandoGravaOComandoEUsaOAgenteDele(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        // O agente do input é ignorado quando há comando: vale o do comando.
        $input = new SolicitarAnaliseDaPastaInput(self::PASTA_ID, Agente::Juridico, Comando::IdentificarRiscos);
        $saida = $this->sut()->executar($input, $this->user, $this->tenant);

        self::assertCount(1, $salvas);
        /** @var AnaliseDeInteligencia $gravada */
        $gravada = $salvas[0];
        self::assertSame(Comando::IdentificarRiscos, $gravada->getComando());
        self::assertSame(Agente::Gestor, $gravada->getAgente());
        self::assertSame('identificar_riscos', $gravada->getContextoResumo()['comando']);
        self::assertSame('identificar_riscos', $saida->comando);
        self::assertSame('Identificar riscos', $saida->comandoRotulo);
        self::assertSame('gestor', $saida->agente);
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('comando: a consulta de idempotência vai POR COMANDO (o repositório recebe o comando)')]
    public function testIdempotenciaConsultaPorComando(): void
    {
        $vistos = [];
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static function (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null) use (&$vistos): ?AnaliseDeInteligencia {
                $vistos[] = ['pendente', $agente, $comando];

                return null;
            },
        );
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturnCallback(
            static function (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null) use (&$vistos): ?AnaliseDeInteligencia {
                $vistos[] = ['ultima', $agente, $comando];

                return null;
            },
        );
        $this->capturarSalva();

        $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::VerificarPrazos), $this->user, $this->tenant);

        self::assertSame([
            ['pendente', Agente::Prazos, Comando::VerificarPrazos],
            ['ultima', Agente::Prazos, Comando::VerificarPrazos],
        ], $vistos);
    }

    #[TestDox('comando: pendente do MESMO comando → devolve a mesma com o rótulo do comando no aviso, sem novo dispatch')]
    public function testPendenteDoMesmoComando(): void
    {
        $pendente = $this->analiseDoComando(Comando::IdentificarRiscos, 'h', false);
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static fn (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null): ?AnaliseDeInteligencia => $comando === Comando::IdentificarRiscos ? $pendente : null,
        );
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::IdentificarRiscos), $this->user, $this->tenant);

        self::assertSame(200, $saida->id);
        self::assertSame('Já existe uma análise "Identificar riscos" em andamento.', $saida->aviso);
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('comando: pendente de OUTRO comando do mesmo agente não trava este')]
    public function testPendenteDeOutroComandoDoMesmoAgenteNaoTrava(): void
    {
        $pendenteDeAnalisar = $this->analiseDoComando(Comando::AnalisarPasta, 'h', false);
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static fn (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null): ?AnaliseDeInteligencia => $comando === Comando::AnalisarPasta ? $pendenteDeAnalisar : null,
        );
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::IdentificarRiscos), $this->user, $this->tenant);

        self::assertCount(1, $salvas);
        self::assertSame('identificar_riscos', $saida->comando);
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('comando: contexto igual ao da última concluída do comando → "Nada novo" com o rótulo do comando')]
    public function testNadaNovoPorComando(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();
        $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::RelatorioPrazos), $this->user, $this->tenant);
        $hash = $salvas[0]->getContextoHash();

        $this->setUp();
        $ultima = $this->analiseDoComando(Comando::RelatorioPrazos, $hash, true);
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturnCallback(
            static fn (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null): ?AnaliseDeInteligencia => $comando === Comando::RelatorioPrazos ? $ultima : null,
        );
        $this->analises->expects($this->never())->method('salvar');

        $saida = $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::RelatorioPrazos), $this->user, $this->tenant);

        self::assertSame(200, $saida->id);
        self::assertSame('Nada novo desde a última análise "Relatório de prazos".', $saida->aviso);
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('o comando entra no hash: dois comandos do mesmo agente, mesmos dados, hashes diferentes; sem comando, o hash de antes')]
    public function testComandoEntraNoHash(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::AnalisarPasta), $this->user, $this->tenant);
        $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::IdentificarRiscos), $this->user, $this->tenant);
        $this->sut()->executar($this->input(Agente::Gestor), $this->user, $this->tenant);

        self::assertCount(3, $salvas);
        self::assertNotSame($salvas[0]->getContextoHash(), $salvas[1]->getContextoHash());
        self::assertNotSame($salvas[0]->getContextoHash(), $salvas[2]->getContextoHash());
    }

    #[TestDox('compatibilidade: sem comando, o pedido continua por agente (repositório sem comando, linha e resumo sem comando)')]
    public function testSemComandoContinuaPorAgente(): void
    {
        $comandosVistos = [];
        $this->analises->method('findPendenteDoAlvo')->willReturnCallback(
            static function (Tenant $t, string $alvo, int $id, TipoDeAnalise $tipo, ?Agente $agente = null, ?Comando $comando = null) use (&$comandosVistos): ?AnaliseDeInteligencia {
                $comandosVistos[] = $comando;

                return null;
            },
        );
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->input(Agente::Prazos), $this->user, $this->tenant);

        self::assertSame([null], $comandosVistos);
        self::assertNull($salvas[0]->getComando());
        self::assertSame(Agente::Prazos, $salvas[0]->getAgente());
        self::assertArrayNotHasKey('comando', $salvas[0]->getContextoResumo());
        self::assertNull($saida->comando);
    }

    // ---------------------------------------------------------------------------------------
    // Índice inteligente da aba Documentos (spec inteligencia-drawer-da-pasta, F4)
    // ---------------------------------------------------------------------------------------

    private function documentoDaPasta(int $id, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setTenant($this->tenant);
        $d->setPasta($this->pasta);
        $d->setTitulo('Documento ' . $id);
        $d->setCategoria($categoria);
        $d->setCaminhoArquivo('x/doc-' . $id . '.pdf');
        $d->setNomeOriginal('doc-' . $id . '.pdf');
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(100);
        DefineId::em($d, $id);

        return $d;
    }

    /** @param list<string> $documentos */
    private function inputDoIndice(array $documentos): SolicitarAnaliseDaPastaInput
    {
        return SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::IndiceDosDocumentos, $documentos);
    }

    private function semCriar(): callable
    {
        return static function (): void {
            self::fail('seleção inválida não chega ao limitador');
        };
    }

    private function esperarSelecaoInvalida(SolicitarAnaliseDaPastaInput $input, string $trecho): void
    {
        $this->analises->expects($this->never())->method('salvar');
        $this->analises->expects($this->never())->method('findPendenteDoAlvo');

        try {
            $this->sut()->executar($input, $this->user, $this->tenant, $this->semCriar());
            self::fail('deveria ter recusado a seleção');
        } catch (SelecaoDeDocumentosInvalidaException $e) {
            self::assertSame('selecao_invalida', $e->motivo());
            self::assertStringContainsString($trecho, $e->getMessage());
        }
        self::assertSame([], $this->despachadas);
    }

    // ---------------------------------------------------------------------------------------
    // "Ler N documentos" (PR F da spec ia-anthropic-ligar)
    // ---------------------------------------------------------------------------------------

    /** @param list<string> $documentos */
    private function inputDaLeitura(array $documentos): SolicitarAnaliseDaPastaInput
    {
        return SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::LerDocumentos, $documentos);
    }

    private function esperarRecusaDaLeitura(SolicitarAnaliseDaPastaInput $input, string $classe, string $motivo, string $trecho): void
    {
        $this->analises->expects($this->never())->method('salvar');
        $this->analises->expects($this->never())->method('findPendenteDoAlvo');

        try {
            $this->sut()->executar($input, $this->user, $this->tenant, $this->semCriar());
            self::fail('deveria ter recusado a leitura');
        } catch (SelecaoDeDocumentosInvalidaException|AceiteDesatualizadoException $e) {
            self::assertInstanceOf($classe, $e);
            self::assertSame($motivo, $e->motivo());
            self::assertStringContainsString($trecho, $e->getMessage());
        }
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('Ler: seleção válida → comando Ler, Documental, versão da leitura, ids ordenados e despacho')]
    public function testLeituraSelecaoValida(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(2), $this->documentoDaPasta(1)];
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->inputDaLeitura(['2', '1']), $this->user, $this->tenant);

        /** @var AnaliseDeInteligencia $gravada */
        $gravada = $salvas[0];
        self::assertSame(Comando::LerDocumentos, $gravada->getComando());
        self::assertSame(Agente::Documental, $gravada->getAgente());
        self::assertSame(PromptDoAgente::VERSAO_DA_LEITURA, $gravada->getVersaoDoPrompt());
        self::assertSame([1, 2], $gravada->getContextoResumo()['documentosSelecionados']);
        self::assertArrayNotHasKey('documentosLidos', $gravada->getContextoResumo(), 'o sha256 é do worker, que lê o arquivo');
        self::assertSame('ler_documentos', $saida->comando);
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('Ler: mais de 3 documentos → limite_da_leitura, nada persistido nem despachado')]
    public function testLeituraMaisDeTres(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(4), $this->documentoDaPasta(3), $this->documentoDaPasta(2), $this->documentoDaPasta(1)];

        $this->esperarRecusaDaLeitura($this->inputDaLeitura(['1', '2', '3', '4']), SelecaoDeDocumentosInvalidaException::class, 'limite_da_leitura', 'no máximo 3');
    }

    #[TestDox('Ler: formato que não é PDF nem imagem → limite_da_leitura com o nome do arquivo')]
    public function testLeituraFormatoNaoLido(): void
    {
        $planilha = $this->documentoDaPasta(1);
        $planilha->setNomeOriginal('contas.xlsx');
        $planilha->setMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->dados->documentos = [$planilha];

        $this->esperarRecusaDaLeitura($this->inputDaLeitura(['1']), SelecaoDeDocumentosInvalidaException::class, 'limite_da_leitura', 'contas.xlsx');
    }

    #[TestDox('Ler: id de outra pasta ou escritório → selecao_invalida, como no Índice')]
    public function testLeituraIdDeOutraPasta(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];

        $this->esperarRecusaDaLeitura($this->inputDaLeitura(['999']), SelecaoDeDocumentosInvalidaException::class, 'selecao_invalida', 'não está mais na lista');
    }

    #[TestDox('Ler: aceite anterior ao texto que cita os documentos → AceiteDesatualizado; o Índice segue')]
    public function testLeituraComAceiteAntigo(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];
        $config = $this->configuracoes->findDoTenant($this->tenant);
        self::assertNotNull($config);
        (new \ReflectionProperty($config, 'consentimentoVersao'))->setValue($config, 1);

        $this->esperarRecusaDaLeitura($this->inputDaLeitura(['1']), AceiteDesatualizadoException::class, 'aceite_desatualizado', 'renova o aceite');
    }

    #[TestDox('Índice: seleção válida → Documental, ids ordenados e sem repetição em documentosSelecionados, e a seção leva só os marcados')]
    public function testIndiceSelecaoValida(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(3), $this->documentoDaPasta(2), $this->documentoDaPasta(1)];
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $saida = $this->sut()->executar($this->inputDoIndice(['3', '1', '3']), $this->user, $this->tenant);

        /** @var AnaliseDeInteligencia $gravada */
        $gravada = $salvas[0];
        self::assertSame(Comando::IndiceDosDocumentos, $gravada->getComando());
        self::assertSame(Agente::Documental, $gravada->getAgente());
        $resumo = $gravada->getContextoResumo();
        self::assertSame([1, 3], $resumo['documentosSelecionados']);
        self::assertSame(2, $resumo['secoes']['documentos']);
        self::assertArrayNotHasKey('documentos', $resumo['omitidas'], 'os não marcados não são "omitidos por limite"');
        self::assertSame('indice_documentos', $saida->comando);
        self::assertCount(1, $this->despachadas);
    }

    #[TestDox('Índice: a seleção muda o hash ("nada novo" vale por seleção)')]
    public function testIndiceSelecaoMudaOHash(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(2), $this->documentoDaPasta(1)];
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar($this->inputDoIndice(['1']), $this->user, $this->tenant);
        $this->sut()->executar($this->inputDoIndice(['1', '2']), $this->user, $this->tenant);
        $this->sut()->executar($this->inputDoIndice(['2', '1']), $this->user, $this->tenant);

        self::assertNotSame($salvas[0]->getContextoHash(), $salvas[1]->getContextoHash());
        self::assertSame($salvas[1]->getContextoHash(), $salvas[2]->getContextoHash(), 'a ordem do clique não importa');
    }

    #[TestDox('Índice: seleção vazia → SelecaoDeDocumentosInvalidaException, sem consultar idempotência, sem limitador, nada persistido')]
    public function testIndiceSelecaoVazia(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];

        $this->esperarSelecaoInvalida($this->inputDoIndice([]), 'Marque ao menos um documento');
    }

    #[TestDox('Índice: id que não é da pasta (outra pasta ou outro escritório: a fonte, com tenant e pasta, não o devolve) → recusa')]
    public function testIndiceIdDeOutraPasta(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];

        $this->esperarSelecaoInvalida($this->inputDoIndice(['1', '999']), 'não está mais na lista');
    }

    #[TestDox('Índice: valor que não é id inteiro → recusa')]
    public function testIndiceIdNaoNumerico(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];

        $this->esperarSelecaoInvalida($this->inputDoIndice(['1', 'abc']), 'não está mais na lista');
    }

    #[TestDox('Índice: contrato de honorários com o Financeiro oculto para quem pede (oculto pelas abas) → recusa')]
    public function testIndiceIdOculto(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(2, PastaDocumento::CATEGORIA_CONTRATO), $this->documentoDaPasta(1)];
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Financeiro, $this->user, new \DateTimeImmutable())];

        $this->esperarSelecaoInvalida($this->inputDoIndice(['2']), 'não está mais na lista');
    }

    #[TestDox('Índice: aba Documentos oculta para quem pede → nenhum documento pode ser marcado')]
    public function testIndiceAbaDocumentosOculta(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(1)];
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant, $this->pasta, AbaDaPasta::Documentos, $this->user, new \DateTimeImmutable())];

        $this->esperarSelecaoInvalida($this->inputDoIndice(['1']), 'não está mais na lista');
    }

    #[TestDox('Índice: documento fora dos LIMITE_DOCUMENTOS mais recentes (fora da lista da aba) → recusa; o último da lista vale')]
    public function testIndiceIdForaDoLimite(): void
    {
        $limite = MontadorDeContextoDaPasta::LIMITE_DOCUMENTOS;
        $documentos = [];
        for ($id = $limite + 1; $id >= 1; --$id) {
            $documentos[] = $this->documentoDaPasta($id); // mais recente primeiro: o id 1 é o (limite+1)-ésimo
        }
        $this->dados->documentos = $documentos;

        $this->esperarSelecaoInvalida($this->inputDoIndice(['1']), 'não está mais na lista');

        $this->setUp();
        $this->dados->documentos = $documentos;
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();
        $this->sut()->executar($this->inputDoIndice(['2']), $this->user, $this->tenant);
        self::assertSame([2], $salvas[0]->getContextoResumo()['documentosSelecionados']);
    }

    #[TestDox('Índice em andamento com OUTRA seleção → devolve o em andamento com o aviso da seleção; com a MESMA, o aviso de sempre')]
    public function testIndiceEmAndamentoComOutraSelecao(): void
    {
        $this->dados->documentos = [$this->documentoDaPasta(2), $this->documentoDaPasta(1)];
        $pendente = new AnaliseDeInteligencia($this->tenant, $this->user, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, self::PASTA_ID, PromptDoAgente::VERSAO, 'h', ['total' => 1, 'documentosSelecionados' => [1]], Agente::Documental, Comando::IndiceDosDocumentos);
        DefineId::em($pendente, 300);
        $this->analises->method('findPendenteDoAlvo')->willReturn($pendente);
        $this->analises->expects($this->never())->method('salvar');

        $outra = $this->sut()->executar($this->inputDoIndice(['2']), $this->user, $this->tenant, $this->semCriar());
        $mesma = $this->sut()->executar($this->inputDoIndice(['1']), $this->user, $this->tenant, $this->semCriar());

        self::assertSame(300, $outra->id);
        self::assertSame('Já há um índice em andamento com outra seleção de documentos. Espere terminar para pedir outro.', $outra->aviso);
        self::assertSame(300, $mesma->id);
        self::assertSame('Já existe uma análise "Índice inteligente" em andamento.', $mesma->aviso);
        self::assertSame([], $this->despachadas);
    }

    #[TestDox('outro comando: `documentos` é ignorado (sem validação, sem recorte, sem chave no resumo)')]
    public function testOutroComandoIgnoraDocumentos(): void
    {
        $this->analises->method('findPendenteDoAlvo')->willReturn(null);
        $this->analises->method('findUltimaConcluidaDoAlvo')->willReturn(null);
        $salvas = $this->capturarSalva();

        $this->sut()->executar(SolicitarAnaliseDaPastaInput::doComando(self::PASTA_ID, Comando::IdentificarRiscos, ['999']), $this->user, $this->tenant);

        self::assertArrayNotHasKey('documentosSelecionados', $salvas[0]->getContextoResumo());
    }
}
