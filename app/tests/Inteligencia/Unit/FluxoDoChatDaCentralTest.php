<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Fluxo\FluxoDoChatDaCentral;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDoChat;
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
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\CorpusDeInjecao;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeDadosDaPastaFalsa;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(FluxoDoChatDaCentral::class)]
final class FluxoDoChatDaCentralTest extends TestCase
{
    private const PASTA_ID = 7;
    private const NUP_DO_PROCESSO = '07011345720258070007';
    private const OUTRA_PASTA_ID = 8;
    private const NUP_DA_OUTRA = '08022456720258070008';

    /** @var list<array{string, int, string}> */
    private array $gravadas = [];

    private Pasta $pasta;
    private Pasta $outraPasta;
    private Processo $outroProcesso;
    private bool $temAceite = true;

    /** @var list<int> pastas que a pessoa não vê (cadeado) */
    private array $pastasOcultas = [];

    /** @var list<int> pastas cujo financeiro a pessoa não vê */
    private array $semFinanceiro = [];
    private Processo $processo;
    private bool $podeVerPasta = true;
    private bool $podeVerFinanceiro = true;

    /** @var list<AbaOcultaDaPasta> */
    private array $regrasDeAba = [];

    protected function setUp(): void
    {
        $this->gravadas = [];
        $this->podeVerPasta = true;
        $this->podeVerFinanceiro = true;
        $this->regrasDeAba = [];
        $this->temAceite = true;
        $this->pastasOcultas = [];
        $this->semFinanceiro = [];

        $tenant = $this->tenant();
        $this->processo = new Processo();
        $this->processo->setTenant($tenant);
        $this->processo->setNumeroProcesso(self::NUP_DO_PROCESSO);
        DefineId::em($this->processo, 3);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($tenant);
        $this->pasta->setNup('1234');
        $this->pasta->vincularProcesso($this->processo);
        DefineId::em($this->pasta, self::PASTA_ID);

        $this->outroProcesso = new Processo();
        $this->outroProcesso->setTenant($tenant);
        $this->outroProcesso->setNumeroProcesso(self::NUP_DA_OUTRA);
        DefineId::em($this->outroProcesso, 4);
        $this->outraPasta = new Pasta();
        $this->outraPasta->setTenant($tenant);
        $this->outraPasta->setNup('5678');
        $this->outraPasta->vincularProcesso($this->outroProcesso);
        DefineId::em($this->outraPasta, self::OUTRA_PASTA_ID);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 7);

        return $tenant;
    }

    private function usuario(): User
    {
        $user = new User();
        DefineId::em($user, 3);

        return $user;
    }

    private function analise(Tenant $tenant, ?User $solicitante, array $resumo = ['mensagemId' => 55], string $alvoTipo = AnaliseDeInteligencia::ALVO_CONVERSA_IA): AnaliseDeInteligencia
    {
        return new AnaliseDeInteligencia($tenant, $solicitante, TipoDeAnalise::ChatDaCentral, $alvoTipo, 9, PromptDoChatDaCentral::VERSAO, 'h', $resumo);
    }

    /** @param array<string, mixed>|null $contexto */
    private function fluxo(?array $contexto): FluxoDoChatDaCentral
    {
        $central = new class ($contexto, $this) implements ConversaDaIaNaCentral {
            public function __construct(private readonly ?array $contexto, private readonly FluxoDoChatDaCentralTest $teste)
            {
            }

            public function contextoDaPergunta(Tenant $tenant, int $conversaId, int $mensagemId, User $solicitante): ?array
            {
                return $this->contexto;
            }

            public function gravarResposta(Tenant $tenant, int $conversaId, User $dono, string $texto, \DateTimeImmutable $agora): void
            {
                $this->teste->registrar($texto, $conversaId);
            }

            public function apagarMensagensDaIaAntesDe(\DateTimeImmutable $limite): int
            {
                return 0;
            }
        };
        $mascarador = new MascaradorDeDadosPessoais();
        $movimentacoes = new FonteDeMovimentacoesFalsa();
        $configuracoes = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $aceite = new ConfiguracaoDeInteligencia($this->tenant());
        if ($this->temAceite) {
            $aceite->atualizar(true, 10, 100, true, $this->usuario());
        }
        $comAceite = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $comAceite->method('findDoTenant')->willReturn($aceite);
        $montadorDoPush = new MontadorDeContextoDoPush($movimentacoes, $configuracoes, $mascarador);
        $montadorDaPasta = new MontadorDeContextoDaPasta(new FonteDeDadosDaPastaFalsa(), $movimentacoes, $montadorDoPush, $configuracoes, $mascarador, new MockClock('2026-10-27 10:00:00'));

        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findOneBy')->willReturnCallback(
            function (array $criterios): ?Pasta {
                if (($criterios['tenant'] ?? null)?->getId() !== $this->pasta->getTenant()?->getId()) {
                    return null;
                }

                return match ($criterios['id'] ?? null) {
                    self::PASTA_ID => $this->pasta,
                    self::OUTRA_PASTA_ID => $this->outraPasta,
                    default => null,
                };
            },
        );
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessResource')->willReturnCallback(fn (User $u, Tenant $t, string $recurso, int $id): bool => $this->podeVerPasta && !\in_array($id, $this->pastasOcultas, true));
        $acessoAPasta = new AcessoAPasta((new \ReflectionClass(NivelNoCadeado::class))->newInstanceWithoutConstructor(), $permissoes);
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(fn (User $u, Tenant $t, Pasta $p): bool => $this->podeVerFinanceiro && !\in_array($p->getId(), $this->semFinanceiro, true));
        $regras = $this->createStub(AbaOcultaDaPastaRepository::class);
        $regras->method('daPasta')->willReturnCallback(fn (): array => $this->regrasDeAba);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $primarios = new RegraDosPrimarios($vinculos);
        $abas = new VisibilidadeDasAbasDaPasta($regras, $primarios, new EquipeQuePodeTerAbaOculta($vinculos, $primarios));

        return new FluxoDoChatDaCentral(
            $central,
            new PromptDoChatDaCentral(new MockClock('2026-10-27 10:00:00')),
            new InterpretadorDeRespostaDoChat(),
            $montadorDoPush,
            new MockClock('2026-10-27 10:00:00'),
            $pastas,
            $acessoAPasta,
            $financeiro,
            $abas,
            $montadorDaPasta,
            new PromptDoAgente(),
            $comAceite,
        );
    }

    public function registrar(string $texto, int $conversaId): void
    {
        $this->gravadas[] = ['', $conversaId, $texto];
    }

    #[TestDox('tipo é chat_central')]
    public function testTipo(): void
    {
        self::assertSame(TipoDeAnalise::ChatDaCentral, $this->fluxo(null)->tipo());
    }

    #[TestDox('contexto null da Central: \LogicException (o handler marca falhou sem chamar o provedor)')]
    public function testContextoNull(): void
    {
        $this->expectException(\LogicException::class);

        $this->fluxo(null)->preparar($this->analise($this->tenant(), $this->usuario()), $this->tenant());
    }

    #[TestDox('sem solicitante, sem mensagemId ou com alvo que não é conversa: \LogicException')]
    public function testAnaliseInconsistente(): void
    {
        $contexto = ['texto' => 'Oi', 'nomeDaPessoa' => 'Ana', 'historico' => []];
        $casos = [
            $this->analise($this->tenant(), null),
            $this->analise($this->tenant(), $this->usuario(), []),
            $this->analise($this->tenant(), $this->usuario(), ['mensagemId' => 55], AnaliseDeInteligencia::ALVO_PASTA),
        ];
        foreach ($casos as $analise) {
            try {
                $this->fluxo($contexto)->preparar($analise, $this->tenant());
                self::fail('devia lançar');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('o aoConcluir grava a resposta e os pontos como linhas "• " na conversa da análise; sem texto de pasta, equipe ou produtividade no sistema')]
    public function testAoConcluir(): void
    {
        $this->fluxo(null)->aoConcluir($this->analise($this->tenant(), $this->usuario()), $this->tenant(), new ResultadoDaAnalise("Resposta\nem duas linhas", [['tipo' => 'info', 'texto' => 'Ponto um'], ['tipo' => 'ok', 'texto' => 'Ponto dois']], null));

        self::assertSame([['', 9, "Resposta\nem duas linhas\n• Ponto um\n• Ponto dois"]], $this->gravadas);
    }

    // ---------------------------------------------------------------------------------------
    // Contexto da tela (PR 4a): pastaId no contexto_resumo
    // ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function contextoComHistorico(): array
    {
        return [
            'texto' => 'Resuma esta pasta',
            'nomeDaPessoa' => 'Ana',
            'historico' => [
                ['de' => 'usuario', 'texto' => 'Pergunta antiga da Ana'],
                ['de' => 'ia', 'texto' => 'Resposta antiga da IA sobre OUTRA pasta'],
                ['de' => 'usuario', 'texto' => 'Segunda pergunta da Ana'],
            ],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function analiseComPasta(array $extra = [], ?Tenant $tenant = null): AnaliseDeInteligencia
    {
        return $this->analise($tenant ?? $this->tenant(), $this->usuario(), array_merge(['mensagemId' => 55, 'pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []], $extra));
    }

    #[TestDox('sem pastaId: prompt v1 (sem bloco da pasta), histórico com a fala da IA e resumo sem os campos novos')]
    public function testSemPastaContinuaV1(): void
    {
        $this->podeVerPasta = false;   // sem pastaId a pasta nem é consultada
        $analise = $this->analise($this->tenant(), $this->usuario());

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertNotNull($pedido);
        self::assertSame('chat_central/chat-central-v1', $pedido->rotuloDeUso);
        $texto = $pedido->textoDoUsuario();
        self::assertStringNotContainsString('<pasta>', $texto);
        self::assertStringContainsString('BlueJus IA: Resposta antiga', $texto);
        self::assertSame(['conversaId' => 9, 'mensagemId' => 55, 'historico' => 3, 'total' => 1], $analise->getContextoResumo());
        self::assertSame(hash('sha256', '9|55'), $analise->getContextoHash());
    }

    #[TestDox('com pasta: prompt chat-central-v2 com <pasta>; resumo só com ids, flags e contagens (sem NUP); histórico SÓ com as perguntas da pessoa')]
    public function testComPasta(): void
    {
        $analise = $this->analiseComPasta();

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertNotNull($pedido);
        self::assertSame('chat_central/chat-central-v2', $pedido->rotuloDeUso);
        $texto = $pedido->textoDoUsuario();
        self::assertStringContainsString('<pasta>', $texto);
        self::assertStringContainsString('1234', $texto);
        self::assertStringContainsString('<processos_vinculados>', $texto);
        self::assertStringContainsString('Pergunta antiga da Ana', $texto);
        self::assertStringContainsString('Segunda pergunta da Ana', $texto);
        self::assertStringNotContainsString('Resposta antiga da IA', $texto, 'regra 4: a fala anterior da IA não volta');
        self::assertStringNotContainsString('BlueJus IA:', $texto);
        self::assertStringContainsString('Resuma esta pasta', $texto);

        $resumo = $analise->getContextoResumo();
        self::assertSame(['conversaId' => 9, 'mensagemId' => 55, 'historico' => 2, 'total' => 1, 'pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []], $resumo);
        $json = (string) json_encode($resumo);
        self::assertStringNotContainsString(self::NUP_DO_PROCESSO, $json);
        self::assertStringNotContainsString('1234', $json);
        self::assertStringNotContainsString('Resuma', $json);
        self::assertNotSame(hash('sha256', '9|55'), $analise->getContextoHash(), 'o hash inclui a pasta');
    }

    #[TestDox('o prompt v2 só existe com pasta: a mesma análise sem pastaId não leva o rótulo v2')]
    public function testV2SoComPasta(): void
    {
        $semPasta = $this->fluxo(self::contextoComHistorico())->preparar($this->analise($this->tenant(), $this->usuario()), $this->tenant());
        $comPasta = $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());

        self::assertSame('chat_central/chat-central-v1', $semPasta?->rotuloDeUso);
        self::assertSame('chat_central/chat-central-v2', $comPasta?->rotuloDeUso);
    }

    /** @param array<string, mixed> $extra */
    private function analiseComClientes(array $extra = []): AnaliseDeInteligencia
    {
        $pastas = [
            ['pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []],
            ['pastaId' => self::OUTRA_PASTA_ID, 'financeiro' => true, 'abasOcultas' => []],
        ];

        return $this->analiseComPasta(array_merge(['escopo' => 'clientes', 'pastasIds' => [self::PASTA_ID, self::OUTRA_PASTA_ID], 'pastas' => $pastas, 'naoConsultadas' => 0], $extra));
    }

    #[TestDox('escopo clientes: prompt v3 com um grupo por pasta (aberta primeiro); resumo só com ids e flags, sem NUP; histórico sem a fala da IA')]
    public function testComClientes(): void
    {
        $analise = $this->analiseComClientes();

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertNotNull($pedido);
        self::assertSame('chat_central/chat-central-v3', $pedido->rotuloDeUso);
        $texto = $pedido->textoDoUsuario();
        self::assertSame(2, substr_count($texto, '</pasta>'));
        self::assertLessThan(strpos($texto, '5678'), strpos($texto, '1234'), 'a aberta vem primeiro');
        self::assertStringContainsString('PASTA 1 (ABERTA NA TELA)', $texto);
        self::assertStringNotContainsString('BlueJus IA:', $texto);
        self::assertStringNotContainsString('não puderam', $texto);

        $resumo = $analise->getContextoResumo();
        self::assertSame('clientes', $resumo['escopo']);
        self::assertSame([self::PASTA_ID, self::OUTRA_PASTA_ID], $resumo['pastasIds']);
        $json = (string) json_encode($resumo);
        foreach ([self::NUP_DO_PROCESSO, self::NUP_DA_OUTRA, '1234', '5678'] as $segredo) {
            self::assertStringNotContainsString($segredo, $json);
        }
    }

    #[TestDox('escopo clientes: pasta adicional com segredo de justiça é omitida, contada, e nada dela (NUP, nome) chega ao prompt')]
    public function testAdicionalComSegredoOmitida(): void
    {
        $this->outroProcesso->setNivelSigilo(1);
        $analise = $this->analiseComClientes();

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertNotNull($pedido);
        $texto = $pedido->textoDoUsuario();
        self::assertSame(1, substr_count($texto, '</pasta>'));
        self::assertStringContainsString('1 pasta não pôde ser consultada.', $texto);
        self::assertStringNotContainsString('5678', $texto);
        self::assertStringNotContainsString(self::NUP_DA_OUTRA, $texto);
        self::assertSame([self::PASTA_ID], $analise->getContextoResumo()['pastasIds']);
        self::assertSame(1, $analise->getContextoResumo()['naoConsultadas']);
    }

    #[TestDox('escopo clientes: a contagem da solicitação soma à do worker e a frase plural é fixa')]
    public function testContagemSomaEPlural(): void
    {
        $this->pastasOcultas = [self::OUTRA_PASTA_ID];
        $analise = $this->analiseComClientes(['naoConsultadas' => 1]);

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertStringContainsString('2 pastas não puderam ser consultadas.', (string) $pedido?->textoDoUsuario());
        self::assertSame(2, $analise->getContextoResumo()['naoConsultadas']);
    }

    #[TestDox('escopo clientes: adicional que a pessoa deixou de ver ou que ganhou aba oculta é omitida e contada; financeiro perdido também')]
    public function testAdicionalInvalidaOmitida(): void
    {
        $this->pastasOcultas = [self::OUTRA_PASTA_ID];
        $a = $this->analiseComClientes();
        $this->fluxo(self::contextoComHistorico())->preparar($a, $this->tenant());
        self::assertSame(1, $a->getContextoResumo()['naoConsultadas']);

        $this->pastasOcultas = [];
        $this->semFinanceiro = [self::OUTRA_PASTA_ID];
        $b = $this->analiseComClientes();
        $this->fluxo(self::contextoComHistorico())->preparar($b, $this->tenant());
        self::assertSame(1, $b->getContextoResumo()['naoConsultadas']);
        self::assertSame([self::PASTA_ID], $b->getContextoResumo()['pastasIds']);
    }

    #[TestDox('escopo clientes: financeiro é decidido POR pasta, sem herdar da aberta')]
    public function testFinanceiroPorPasta(): void
    {
        $analise = $this->analiseComClientes(['pastas' => [
            ['pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []],
            ['pastaId' => self::OUTRA_PASTA_ID, 'financeiro' => false, 'abasOcultas' => []],
        ]]);

        $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        $pastas = $analise->getContextoResumo()['pastas'];
        self::assertTrue($pastas[0]['financeiro']);
        self::assertFalse($pastas[1]['financeiro']);
    }

    #[TestDox('escopo clientes: pasta aberta que a pessoa não vê mais, ou sem aceite do escritório: \LogicException (falhou sem chamar o provedor)')]
    public function testAbertaInvalidaOuSemAceiteFalha(): void
    {
        $this->pastasOcultas = [self::PASTA_ID];
        try {
            $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComClientes(), $this->tenant());
            self::fail('Devia falhar.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $this->pastasOcultas = [];
        $this->temAceite = false;
        $this->expectException(\LogicException::class);
        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComClientes(), $this->tenant());
    }

    #[TestDox('escopo clientes: segredo de justiça na pasta ABERTA lança ContextoBloqueadoException')]
    public function testAbertaComSegredoBloqueia(): void
    {
        $this->processo->setNivelSigilo(1);

        $this->expectException(ContextoBloqueadoException::class);

        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComClientes(), $this->tenant());
    }

    #[TestDox('escopo clientes: o teto de adicionais gravadas é 4 e ids repetidos ou a aberta repetida não entram duas vezes')]
    public function testTetoEDuplicadas(): void
    {
        $dup = ['pastaId' => self::OUTRA_PASTA_ID, 'financeiro' => true, 'abasOcultas' => []];
        $analise = $this->analiseComClientes(['pastas' => [
            ['pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []], $dup, $dup,
            ['pastaId' => self::PASTA_ID, 'financeiro' => true, 'abasOcultas' => []],
        ]]);

        $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertSame([self::PASTA_ID, self::OUTRA_PASTA_ID], $analise->getContextoResumo()['pastasIds']);
    }

    #[TestDox('escopo clientes: pasta de OUTRO escritório no resumo é omitida (relida por id + escritório)')]
    public function testPastaDeOutroEscritorio(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);
        $this->outraPasta->setTenant($outro);

        $analise = $this->analiseComClientes();
        $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertSame([self::PASTA_ID], $analise->getContextoResumo()['pastasIds']);
        self::assertSame(1, $analise->getContextoResumo()['naoConsultadas']);
    }

    #[TestDox('a pessoa perdeu o acesso à pasta (cadeado): \LogicException com motivo fixo, sem id nem NUP (o handler marca falhou sem chamar o provedor)')]
    public function testPerdeuAcessoAPasta(): void
    {
        $this->podeVerPasta = false;

        try {
            $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());
            self::fail('Devia falhar.');
        } catch (\LogicException $e) {
            self::assertSame('Chat com pasta que a pessoa não pode mais ver.', $e->getMessage());
            self::assertStringNotContainsString('1234', $e->getMessage());
        }
    }

    #[TestDox('vínculo encerrado (a porta devolve null): \LogicException, a pasta nem é lida')]
    public function testVinculoEncerrado(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Chat sem pergunta válida nesta conversa.');

        $this->fluxo(null)->preparar($this->analiseComPasta(), $this->tenant());
    }

    #[TestDox('pasta de OUTRO escritório (ou análise de outro escritório): \LogicException, nunca lê a pasta')]
    public function testTenantErrado(): void
    {
        $outro = new Tenant();
        DefineId::em($outro, 99);

        // A análise é do escritório 99; a pasta é do 7: a releitura por id + escritório não a encontra.
        try {
            $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta([], $outro), $outro);
            self::fail('Devia falhar.');
        } catch (\LogicException $e) {
            self::assertSame('Chat com pasta que não existe neste escritório.', $e->getMessage());
        }

        // E a pasta que mudou de dono depois da solicitação.
        $this->pasta->setTenant($outro);
        $this->expectException(\LogicException::class);
        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());
    }

    #[TestDox('pasta que não existe mais: \LogicException')]
    public function testPastaInexistente(): void
    {
        $this->expectException(\LogicException::class);

        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(['pastaId' => 12345]), $this->tenant());
    }

    #[TestDox('perdeu o financeiro desde a solicitação: falha, sem montar contexto')]
    public function testPerdeuOFinanceiro(): void
    {
        $this->podeVerFinanceiro = false;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Chat com pasta cujo financeiro a pessoa não pode mais ver.');

        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());
    }

    #[TestDox('o financeiro nunca AUMENTA: gravado false e hoje permitido, continua sem <financeiro>')]
    public function testFinanceiroNaoAumenta(): void
    {
        $analise = $this->analiseComPasta(['financeiro' => false]);

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertFalse($analise->getContextoResumo()['financeiro']);
        self::assertStringNotContainsString('<financeiro>', (string) $pedido?->textoDoUsuario());
    }

    #[TestDox('uma aba que a solicitação deixou ler passou a ser oculta: falha')]
    public function testAbaPassouAOcultaFalha(): void
    {
        $this->regrasDeAba = [AbaOcultaDaPasta::paraTodaAEquipe($this->tenant(), $this->pasta, AbaDaPasta::Tarefas, $this->usuario(), new \DateTimeImmutable())];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Chat com pasta cuja aba a pessoa não pode mais ver.');

        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());
    }

    #[TestDox('a aba oculta na solicitação e visível hoje continua oculta (as abas nunca AUMENTAM)')]
    public function testAbasNaoAumentam(): void
    {
        $analise = $this->analiseComPasta(['abasOcultas' => ['tarefas']]);

        $pedido = $this->fluxo(self::contextoComHistorico())->preparar($analise, $this->tenant());

        self::assertNotNull($pedido);
        self::assertSame(['tarefas'], $analise->getContextoResumo()['abasOcultas']);
        self::assertStringNotContainsString('<metas>', $pedido->textoDoUsuario());
    }

    #[TestDox('processo em segredo de justiça: ContextoBloqueadoException antes de montar o pedido (o handler marca falhou)')]
    public function testSegredoDeJustica(): void
    {
        $this->processo->setNivelSigilo(1);

        $this->expectException(ContextoBloqueadoException::class);

        $this->fluxo(self::contextoComHistorico())->preparar($this->analiseComPasta(), $this->tenant());
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        return CorpusDeInjecao::ataques();
    }

    #[DataProvider('corpus')]
    #[TestDox('corpus ($_dataName) na ação da pasta e na pergunta: nenhum bloco novo fecha nem abre por conta do dado')]
    public function testInjecaoNosBlocosNovos(string $ataque): void
    {
        $contexto = ['texto' => 'Resuma. ' . $ataque, 'nomeDaPessoa' => 'Ana', 'historico' => [['de' => 'usuario', 'texto' => 'Antes. ' . $ataque]]];

        $this->pasta->setNomeAcao('Ação benigna');
        $base = $this->fluxo(['texto' => 'Resuma.', 'nomeDaPessoa' => 'Ana', 'historico' => [['de' => 'usuario', 'texto' => 'Antes.']]])->preparar($this->analiseComPasta(), $this->tenant());
        $esperadas = preg_match_all(CorpusDeInjecao::padraoDeTag(), (string) $base?->textoDoUsuario());

        $this->pasta->setNomeAcao('Divórcio. ' . $ataque);
        $pedido = $this->fluxo($contexto)->preparar($this->analiseComPasta(), $this->tenant());

        self::assertNotNull($pedido);
        self::assertSame($esperadas, preg_match_all(CorpusDeInjecao::padraoDeTag(), $pedido->textoDoUsuario()), 'só as tags do próprio prompt: o dado não cria nem fecha bloco');
        self::assertSame(1, substr_count($pedido->textoDoUsuario(), '</pasta>'));
        self::assertSame(1, substr_count($pedido->textoDoUsuario(), '</pergunta>'));
    }

    #[TestDox('todo bloco que o contexto da pasta produz está em NeutralizadorDeConteudo::DELIMITADORES')]
    public function testBlocosNosDelimitadores(): void
    {
        $tags = ['pasta', 'processos_vinculados', 'pessoa', 'historico', 'pergunta'];
        foreach (\App\Inteligencia\Enum\SecaoDoContexto::cases() as $secao) {
            $tags[] = $secao->tag();
        }

        foreach ($tags as $tag) {
            self::assertContains($tag, \App\Inteligencia\Service\NeutralizadorDeConteudo::DELIMITADORES, $tag);
        }
    }
}
