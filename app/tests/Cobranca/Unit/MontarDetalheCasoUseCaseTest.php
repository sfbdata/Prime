<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\DTO\PrescricaoOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraPrescricao;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\EncargosVivos;
use Symfony\Component\Clock\MockClock;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ajuste 10: o mapa `obrigacaoId => alocado` do caso inteiro tem que sair de UMA query, nunca de
 * um loop por obrigação — mesmo padrão do `MontarDetalheAcordoUseCase` (ajuste 7). Dois testes:
 * - Primeiro trava a contagem (UMA chamada, não uma por obrigação) com lista vazia.
 * - Segundo exercita o encaixe (chave certa → obrigação certa) com mapa DIFERENTE por id + asserção
 *   de `alocado` e `restante()` por posição.
 */
#[CoversClass(MontarDetalheCasoUseCase::class)]
final class MontarDetalheCasoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private PagamentoRepository&MockObject $pagamentoRepository;
    private LiquidacaoRepository&MockObject $liquidacaoRepository;
    private AcordoRepository&MockObject $acordoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ProximaAcaoRepository&MockObject $proximaAcaoRepository;
    private CalculadoraSaldo&MockObject $calculadoraSaldo;
    private AlertasCobranca $alertasCobranca;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private CalculadoraHonorarios $calculadoraHonorarios;
    private MontarDetalheCasoUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    /** Relógio fixo do UseCase (o mesmo do `EncargosVivos` injetado abaixo). */
    private const AGORA = '2026-07-20';

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->calculadoraSaldo = $this->createMock(CalculadoraSaldo::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);

        // Defaults neutros: só o comportamento sob teste (as alocações) importa aqui.
        // `doCaso` (obrigação E acordo) NÃO tem default aqui de propósito: a geração automática do
        // PHPUnit já devolve `[]` para um método não-stubado com retorno `array` — e um `willReturn`
        // registrado no setUp travaria como PRIMEIRO matcher, ignorando qualquer stub posterior no
        // corpo do teste (confirmado: o retorno é sempre do primeiro matcher que casa, nunca do
        // último). Cada teste que precisa de obrigações/acordos concretos stuba `doCaso` no próprio
        // corpo (ou via `casoComAcordoVigente`).
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);
        $this->pagamentoRepository->method('doCaso')->willReturn([]);
        $this->liquidacaoRepository->method('doCaso')->willReturn([]);
        // `EventoHistoricoRepository::doCaso` também NÃO é stubado aqui, pelo mesmo motivo dos `doCaso`
        // acima: os testes da listinha de qualificação (§3.4) precisam devolver eventos concretos, e um
        // `willReturn([])` no setUp travaria como PRIMEIRO matcher e venceria qualquer stub do corpo.
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(0);
        $this->calculadoraSaldo->method('saldoVencido')->willReturn(0);

        // AlertasCobranca é `final` (não pode ser mockada) — instancia REAL sobre os mesmos mocks
        // de repositório/serviço já usados acima (nenhuma query nova além das já stubadas).
        $this->alertasCobranca = new AlertasCobranca(
            $this->obrigacaoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
        );

        // CalculadoraHonorarios também é `final` — e é uma calculadora PURA (sem I/O, só depende do
        // ResolvedorConfigEncargos, também puro): a instância real é a fonte única do gross-up, e
        // mocká-la só esconderia a regra sob teste.
        $this->calculadoraHonorarios = new CalculadoraHonorarios(new ResolvedorConfigEncargos());

        $this->useCase = new MontarDetalheCasoUseCase(
            $this->obrigacaoRepository,
            $this->pagamentoRepository,
            $this->liquidacaoRepository,
            $this->acordoRepository,
            $this->eventoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
            $this->alertasCobranca,
            $this->alocacaoRepository,
            $this->calculadoraHonorarios,
            // ResolvedorConfigEncargos é `final` e PURO (navega o grafo em memória, sem I/O): instância real.
            new ResolvedorConfigEncargos(),
            // EncargosVivos com relógio fixo (aplicador puro): as obrigações do teste têm vencimento/config
            // que não geram encargo, então a hidratação é no-op — o foco do teste é a agregação/DTOs.
            new EncargosVivos(new MockClock(new \DateTimeImmutable(self::AGORA)), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            // CalculadoraPrescricao é `final` e PURA (só conta dias sobre a lista recebida): instância real.
            new CalculadoraPrescricao(),
        );

        $this->tenant = new Tenant();
        $this->autor = (new User())->setFullName('Farlei Rocha');
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->autor, 7);
    }

    #[Test]
    public function carregaAsAlocacoesEmUmaUnicaQuery(): void
    {
        $caso = $this->casoPersistido();

        $this->alocacaoRepository
            ->expects(self::once())            // ← o ponto do teste: UMA vez, não uma por obrigação
            ->method('somasPorObrigacaoDosCasos')
            ->with([$caso->getId()], $caso->getTenant())
            ->willReturn([]);

        $this->useCase->executar($caso);
    }

    /**
     * O teste acima trava o N+1 (uma chamada só), mas nunca prova que a chave certa cai na obrigação
     * certa — um mapa vazio nunca exercita `$alocadoPorObrigacao[$o->getId()] ?? 0`. Espelha o
     * precedente de `MontarDetalheAcordoUseCaseTest::montaDetalheComSnapshotParcelasEDescontoDerivado`
     * (mapa com valores DIFERENTES por chave + asserção por posição/id).
     */
    #[Test]
    public function alocaOValorDaChaveCertaEmCadaObrigacao(): void
    {
        $caso = $this->casoPersistido();

        $obrigacaoA = $this->novaObrigacao($caso, 101, 50000);
        $obrigacaoB = $this->novaObrigacao($caso, 102, 30000);
        $obrigacaoC = $this->novaObrigacao($caso, 103, 20000); // fora do mapa → alocado default 0

        $this->obrigacaoRepository->method('doCaso')->willReturn([$obrigacaoA, $obrigacaoB, $obrigacaoC]);

        // Valores DIFERENTES por chave: se o array_map usar a chave errada (ou um id como string),
        // o valor cai na obrigação vizinha e o teste tem que acusar (ficar vermelho).
        $this->alocacaoRepository
            ->expects(self::once())
            ->method('somasPorObrigacaoDosCasos')
            ->willReturn([101 => 40000, 102 => 10000]);

        $detalhe = $this->useCase->executar($caso);

        self::assertCount(3, $detalhe->obrigacoes);

        $porId = [];
        foreach ($detalhe->obrigacoes as $o) {
            $porId[$o->id] = $o;
        }

        self::assertSame(40000, $porId[101]->alocado, 'alocado da obrigação A vem da SUA chave (101)');
        self::assertSame(10000, $porId[101]->restante(), 'restante = valorAtual (50000) − alocado (40000)');
        self::assertSame(10000, $porId[102]->alocado, 'alocado da obrigação B vem da SUA chave (102), não da de A');
        self::assertSame(0, $porId[103]->alocado, 'obrigação fora do mapa cai no default 0 (?? 0)');
    }

    /**
     * Ajuste 10 (T5, spec §5.1): o prefill do "Receber" é o BRUTO, não o restante. `ratearPagamento`
     * divide o valor DIGITADO — pré-preencher o restante (120000) devolveria dívida 109091 e a obrigação
     * não quitaria. O alvo do gross-up é o RESTANTE (já descontado o alocado), nunca o valor cheio.
     */
    #[Test]
    public function oBrutoSugeridoFazGrossUpSobreORestanteDaObrigacao(): void
    {
        // #9-T2: a política de honorários vem do objeto/carteira, não mais do snapshot do caso.
        $carteira = (new Carteira())->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)->setPercentualHonorarios('10.00');
        $caso = $this->casoPersistido($carteira);

        $semPagamento = $this->novaObrigacao($caso, 101, 120000);
        $parcialmentePaga = $this->novaObrigacao($caso, 102, 120000);
        $quitada = $this->novaObrigacao($caso, 103, 120000);

        $this->obrigacaoRepository->method('doCaso')->willReturn([$semPagamento, $parcialmentePaga, $quitada]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([102 => 40000, 103 => 120000]);

        $porId = [];
        foreach ($this->useCase->executar($caso)->obrigacoes as $o) {
            $porId[$o->id] = $o;
        }

        // D = 120000 → T = 132000 (hon 12000 + dívida 120000): é o número que quita a obrigação.
        self::assertSame(132000, $porId[101]->brutoSugerido);
        // D = restante = 80000 → T = 88000. Mirar o valor cheio cobraria de novo os 40000 já recebidos.
        self::assertSame(88000, $porId[102]->brutoSugerido);
        // Quitada: restante 0 → nada a sugerir (o botão "Receber" nem aparece).
        self::assertSame(0, $porId[103]->brutoSugerido);

        // O round-trip é a garantia (provada em CalculadoraHonorariosTest): o bruto sugerido rateia de
        // volta EXATAMENTE no restante. Sem isto, o prefill mente.
        $calculadora = new CalculadoraHonorarios(new ResolvedorConfigEncargos());
        self::assertSame(
            $porId[102]->restante(),
            $calculadora->ratearPagamento($caso, $porId[102]->brutoSugerido)[0],
        );
    }

    /** Sem percentual o devedor paga só a dívida: o prefill é o próprio restante (espelha `ratearPagamento`). */
    #[Test]
    public function semHonorarioPercentualOBrutoSugeridoEhOProprioRestante(): void
    {
        $caso = $this->casoPersistido();   // nasce `SemPercentual`
        $this->obrigacaoRepository->method('doCaso')->willReturn([$this->novaObrigacao($caso, 101, 120000)]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        self::assertSame(120000, $this->useCase->executar($caso)->obrigacoes[0]->brutoSugerido);
    }

    #[Test]
    public function grupoDoAcordoCarregaAsObrigacoesQueEleSubstituiu(): void
    {
        // Acordo vigente que substituiu Janeiro e Fevereiro e gerou 3 parcelas.
        $caso = $this->casoComAcordoVigente(
            substituidas: ['Janeiro/2026', 'Fevereiro/2026'],
            parcelas: ['Parcela 1 de 3', 'Parcela 2 de 3', 'Parcela 3 de 3'],
        );

        $detalhe = $this->useCase->executar($caso);

        self::assertCount(1, $detalhe->gruposAcordo);
        $grupo = $detalhe->gruposAcordo[0];

        self::assertCount(3, $grupo->parcelas);
        self::assertCount(2, $grupo->substituidas);
        self::assertSame(
            ['Janeiro/2026', 'Fevereiro/2026'],
            array_map(static fn ($o) => $o->descricao, $grupo->substituidas),
        );

        // E não podem ter vazado para a lista solta.
        self::assertSame([], array_map(
            static fn ($o) => $o->descricao,
            array_filter($detalhe->obrigacoesAvulsas, static fn ($o) => $o->substituidaPorAcordo),
        ));
    }

    #[Test]
    public function valorTotalDoGrupoContinuaSomandoSoAsParcelasVivas(): void
    {
        // Blindagem: `valorTotal` é o que bate com o saldo derivado. Expor as substituídas NÃO pode
        // inflá-lo — elas estão FORA do saldo (spec §4.6, armadilha de aritmética).
        $caso = $this->casoComAcordoVigente(
            substituidas: ['Janeiro/2026'],       // 120000
            parcelas: ['Parcela 1 de 2', 'Parcela 2 de 2'],   // 60000 cada
        );

        $grupo = $this->useCase->executar($caso)->gruposAcordo[0];

        self::assertSame(120000, $grupo->valorTotal);   // 2 × 60000, e NÃO 240000
    }

    // ------------------------------------------------- cabeçalho: os quatro cards de dinheiro (§1.2)

    /**
     * O cabeçalho soma sobre o MESMO conjunto que a aba Dívida lista, restrito ao que não está quitado.
     *
     * A obrigação quitada tem valor DIFERENTE em todas as colunas (principal, encargos, honorários): se
     * o filtro `quitada()` cair, os quatro cards se movem juntos e nenhum passa por acidente.
     */
    #[Test]
    #[TestDox('os quatro cards somam só as obrigações em aberto e fecham entre si')]
    public function osTotaisDoCabecalhoSomamSoAsObrigacoesEmAberto(): void
    {
        $caso = $this->casoPersistido();

        $emAberto = $this->novaObrigacao($caso, 101, 100000)->definirEncargos(5000, 2000, 1000, 20000, new \DateTimeImmutable(self::AGORA));
        $quitada = $this->novaObrigacao($caso, 102, 50000)->definirEncargos(1000, 0, 0, 10000, new \DateTimeImmutable(self::AGORA));
        $parcial = $this->novaObrigacao($caso, 103, 30000)->definirEncargos(0, 0, 0, 3000, new \DateTimeImmutable(self::AGORA));

        $this->obrigacaoRepository->method('doCaso')->willReturn([$emAberto, $quitada, $parcial]);
        // 102 exigível = 50000 + 1000 = 51000, todo alocado → quitada, sai dos cards.
        // 103 tem pagamento PARCIAL: continua em aberto e entra inteira (o card é bruto, não saldo).
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([102 => 51000, 103 => 10000]);

        $detalhe = $this->useCase->executar($caso);

        self::assertSame(2, $detalhe->obrigacoesEmAbertoQtd, 'a quitada não conta na linha meta');
        self::assertSame(130000, $detalhe->totalPrincipalEmAberto, '100000 + 30000, sem os 50000 da quitada');
        self::assertSame(8000, $detalhe->totalEncargosEmAberto, 'juros+multa+correção só das em aberto');
        self::assertSame(23000, $detalhe->honorariosEmAberto, 'o card Honorários é o honorário em aberto');
        self::assertSame(161000, $detalhe->totalAtualizadoEmAberto);
        self::assertSame(
            $detalhe->totalPrincipalEmAberto + $detalhe->totalEncargosEmAberto + $detalhe->honorariosEmAberto,
            $detalhe->totalAtualizadoEmAberto,
            'o Total atualizado é a soma dos três cards exibidos ao lado, sempre',
        );
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $detalhe->totaisAtualizadosEm);

        // Blindagem: o total da aba Honorários continua contando TODAS (inclusive a quitada) — os dois
        // números convivem e respondem perguntas diferentes.
        self::assertSame(33000, $detalhe->honorariosDasObrigacoes);
    }

    /**
     * O conjunto somado é o da aba Dívida, não o `doCaso` cru: obrigação substituída por acordo vigente
     * está FORA da lista (virou anexo recolhido do grupo) e fora do exigível. Contá-la inflaria o
     * cabeçalho contra as linhas visíveis logo abaixo — a conferência a olho deixaria de fechar.
     */
    #[Test]
    #[TestDox('o cabeçalho soma as parcelas do acordo vigente e ignora as obrigações que ele substituiu')]
    public function osTotaisIgnoramObrigacaoSubstituidaPorAcordoVigente(): void
    {
        $caso = $this->casoComAcordoVigente(
            substituidas: ['Janeiro/2026'],                          // 120000 — fora da aba
            parcelas: ['Parcela 1 de 2', 'Parcela 2 de 2'],          // 60000 cada — é o que a aba lista
        );

        $detalhe = $this->useCase->executar($caso);

        self::assertSame(2, $detalhe->obrigacoesEmAbertoQtd);
        self::assertSame(120000, $detalhe->totalPrincipalEmAberto, '2 × 60000, e NÃO 240000');
        self::assertSame(120000, $detalhe->totalAtualizadoEmAberto);
    }

    /**
     * Achado BLOQUEANTE da revisão da frente inteira (Etapa 8): romper um acordo devolve a obrigação
     * ORIGINAL ao exigível **e** deixa as parcelas mortas na lista solta. Somar as duas contava o mesmo
     * dinheiro DUAS VEZES, no card mais visível da página — e a própria aba Dívida rotula a parcela como
     * "histórico, fora do total em aberto", então a tela se contradizia.
     *
     * O cenário é montado com valores DIFERENTES entre o original e as parcelas de propósito: se a
     * exclusão cair, nenhum dos quatro cards passa por acidente.
     */
    #[Test]
    #[TestDox('parcela de acordo ROMPIDO fica fora dos cards e da contagem, mas continua no rodapé da aba Honorários')]
    public function osTotaisIgnoramParcelaDeAcordoRompido(): void
    {
        $caso = $this->casoComAcordoRompido(
            original: 'Janeiro/2026',                            // 120000 — VOLTOU ao exigível
            parcelas: ['Parcela 1 de 2', 'Parcela 2 de 2'],      // 60000 cada — mortas
        );

        $detalhe = $this->useCase->executar($caso);

        self::assertSame(1, $detalhe->obrigacoesEmAbertoQtd, 'só a original restaurada está em aberto');
        self::assertSame(
            120000,
            $detalhe->totalPrincipalEmAberto,
            'a original restaurada, e NÃO ela mais as duas parcelas mortas (240000) — isso seria o mesmo dinheiro duas vezes',
        );
        self::assertSame(3000, $detalhe->totalEncargosEmAberto, 'só os encargos da original');
        self::assertSame(24000, $detalhe->honorariosEmAberto, 'a parcela morta não é recebível: o acordo que a criou caiu');
        self::assertSame(147000, $detalhe->totalAtualizadoEmAberto);
        self::assertSame(
            $detalhe->totalPrincipalEmAberto + $detalhe->totalEncargosEmAberto + $detalhe->honorariosEmAberto,
            $detalhe->totalAtualizadoEmAberto,
        );

        // …mas o RODAPÉ da aba Honorários continua somando tudo que a aba LISTA — a parcela morta
        // aparece lá rotulada, e um rodapé que não fechasse com as linhas visíveis seria outro defeito.
        self::assertSame(44000, $detalhe->honorariosDasObrigacoes, '24000 da original + 10000 de cada parcela morta');

        // E a prescrição não pode eleger uma parcela morta como "competência mais antiga": não há o que
        // ajuizar numa parcela que o rompimento do acordo já descartou. As parcelas vencem ANTES da
        // original de propósito — sem a exclusão, uma delas venceria a escolha.
        self::assertNotNull($detalhe->prescricao);
        self::assertSame('Janeiro/2026', $detalhe->prescricao->obrigacaoDescricao);
    }

    // -------------------------------------------------------------- cabeçalho: prescrição (§1.3)

    #[Test]
    #[TestDox('a prescrição olha a competência em aberto mais antiga, ignorando a quitada mais velha')]
    public function aPrescricaoUsaAObrigacaoEmAbertoMaisAntiga(): void
    {
        $caso = $this->casoPersistido();

        $velhaQuitada = $this->novaObrigacao($caso, 101, 40000, 'Janeiro/2020', '2020-01-10');
        $maisAntigaEmAberto = $this->novaObrigacao($caso, 102, 40000, 'Março/2022', '2022-03-15');
        $recente = $this->novaObrigacao($caso, 103, 40000, 'Junho/2024', '2024-06-01');

        $this->obrigacaoRepository->method('doCaso')->willReturn([$velhaQuitada, $maisAntigaEmAberto, $recente]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([101 => 40000]);

        $prescricao = $this->useCase->executar($caso)->prescricao;

        self::assertNotNull($prescricao);
        self::assertSame(102, $prescricao->obrigacaoId, 'a quitada de 2020 não prescreve — não há o que ajuizar');
        self::assertSame('Março/2022', $prescricao->obrigacaoDescricao);
        // Prazo limite 15/03/2027 contra o relógio 20/07/2026: 238 dias, faixa informativa.
        self::assertEquals(new \DateTimeImmutable('2027-03-15'), $prescricao->prazoLimite);
        self::assertSame(238, $prescricao->diasRestantes);
        self::assertSame(PrescricaoOutput::SEVERIDADE_INFORMATIVA, $prescricao->severidade);
    }

    #[Test]
    #[TestDox('sem obrigação em aberto a caixa de prescrição não é montada')]
    public function semObrigacaoEmAbertoNaoHaPrescricao(): void
    {
        $caso = $this->casoPersistido();
        $this->obrigacaoRepository->method('doCaso')->willReturn([$this->novaObrigacao($caso, 101, 40000)]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([101 => 40000]);

        $detalhe = $this->useCase->executar($caso);

        self::assertNull($detalhe->prescricao);
        self::assertSame(0, $detalhe->obrigacoesEmAbertoQtd);
        self::assertSame(0, $detalhe->totalAtualizadoEmAberto);
    }

    // ------------------------------------------------ aba Responsáveis: listinha de qualificação (§3.4)

    #[Test]
    #[TestDox('a listinha traz só as qualificações, mais recente primeiro, e ignora os demais eventos')]
    public function aListinhaTrazSoAsQualificacoes(): void
    {
        $caso = $this->casoPersistido();

        // O contato carrega um payload com a MESMA chave `qualificacao` de propósito: `dados` é JSON
        // livre, e sem o filtro por TIPO a listinha aceitaria qualquer evento cujo payload por acaso
        // tenha essa chave. É o filtro de tipo que precisa barrá-lo — não a leitura do payload.
        $intruso = $this->evento($caso, 897, TipoEventoHistorico::ContatoRealizado, '2026-07-19 23:57:00')
            ->setDados(['qualificacao' => QualificacaoContato::RecusaPagamento->value]);

        // Na ordem em que `doCaso` devolve: mais recente primeiro.
        $this->eventoRepository->method('doCaso')->willReturn([
            $this->qualificacao($caso, 900, QualificacaoContato::RecusaPagamento, '2026-07-19 23:58:00'),
            $this->evento($caso, 899, TipoEventoHistorico::Anotacao, '2026-07-19 23:57:30'),
            $intruso,
            $this->qualificacao($caso, 898, QualificacaoContato::PromessaPagamento, '2026-07-19 10:00:00'),
        ]);

        $detalhe = $this->useCase->executar($caso, $this->autor);

        self::assertCount(2, $detalhe->qualificacoes, 'nem a anotação nem o contato entram na listinha');
        self::assertSame([900, 898], array_map(static fn ($q) => $q->eventoId, $detalhe->qualificacoes));
        self::assertSame('Recusa de pagamento', $detalhe->qualificacoes[0]->label);
        self::assertSame('Promessa de pagamento', $detalhe->qualificacoes[1]->label);
        self::assertSame('Farlei Rocha', $detalhe->qualificacoes[0]->autorNome);

        // O histórico completo continua trazendo os quatro — a listinha é um recorte, não um filtro global.
        self::assertCount(4, $detalhe->historico);
    }

    /**
     * O botão desfazer só aparece para a PRIMEIRA linha. As duas qualificações deste teste estão dentro
     * dos 5 minutos e são do mesmo autor: as três condições que o evento sabe responder sozinho valem
     * para ambas. A única coisa que separa uma da outra é a posição — que é exatamente a quarta condição
     * que o servidor cobra no `DesfazerQualificacaoContatoUseCase`.
     */
    #[Test]
    #[TestDox('só a qualificação mais recente recebe o botão desfazer')]
    public function soAMaisRecentePodeSerDesfeita(): void
    {
        $caso = $this->casoPersistido();

        $this->eventoRepository->method('doCaso')->willReturn([
            $this->qualificacao($caso, 900, QualificacaoContato::RecusaPagamento, '2026-07-19 23:58:00'),
            $this->qualificacao($caso, 899, QualificacaoContato::PromessaPagamento, '2026-07-19 23:57:00'),
        ]);

        $qualificacoes = $this->useCase->executar($caso, $this->autor)->qualificacoes;

        self::assertTrue($qualificacoes[0]->podeDesfazer);
        self::assertFalse($qualificacoes[1]->podeDesfazer, 'dentro da janela, mas não é a mais recente');
    }

    /**
     * `ultimaQualificacaoDoCaso` (a guarda do servidor) escolhe o evento mais recente do tipo SEM olhar
     * o payload. Se a linha de payload quebrado sumisse da lista e o `ehMaisRecente` escorregasse para a
     * seguinte, a tela ofereceria um botão que a rota vai recusar — o desencontro exato que decidir o
     * `podeDesfazer` no servidor existe para evitar.
     */
    #[Test]
    #[TestDox('qualificação de payload irreconhecível some da lista sem passar o desfazer adiante')]
    public function payloadQuebradoNaoTransfereODesfazerParaASeguinte(): void
    {
        $caso = $this->casoPersistido();

        $quebrada = $this->evento($caso, 900, TipoEventoHistorico::QualificacaoContato, '2026-07-19 23:58:00')
            ->setDados(['qualificacao' => 'tipo_que_nao_existe']);

        $this->eventoRepository->method('doCaso')->willReturn([
            $quebrada,
            $this->qualificacao($caso, 899, QualificacaoContato::RecusaPagamento, '2026-07-19 23:57:00'),
        ]);

        $qualificacoes = $this->useCase->executar($caso, $this->autor)->qualificacoes;

        self::assertCount(1, $qualificacoes, 'a linha quebrada é pulada, não derruba a página');
        self::assertSame(899, $qualificacoes[0]->eventoId);
        self::assertFalse(
            $qualificacoes[0]->podeDesfazer,
            'a mais recente do TIPO continua sendo a quebrada — o botão não pode escorregar para esta',
        );
    }

    /** Sem leitor, ninguém pode desfazer nada — mesmo dentro da janela (a Central lê assim). */
    #[Test]
    public function semUsuarioAtualNenhumaQualificacaoPodeSerDesfeita(): void
    {
        $caso = $this->casoPersistido();
        $this->eventoRepository->method('doCaso')->willReturn([
            $this->qualificacao($caso, 900, QualificacaoContato::RecusaPagamento, '2026-07-19 23:58:00'),
        ]);

        self::assertFalse($this->useCase->executar($caso)->qualificacoes[0]->podeDesfazer);
    }

    /** A listinha e o histórico saem da MESMA leitura: duas consultas poderiam divergir na ordem. */
    #[Test]
    public function aLinhaDoTempoEhLidaUmaVezSo(): void
    {
        $caso = $this->casoPersistido();

        $this->eventoRepository->expects(self::once())->method('doCaso')->willReturn([
            $this->qualificacao($caso, 900, QualificacaoContato::RecusaPagamento, '2026-07-19 23:58:00'),
        ]);

        $detalhe = $this->useCase->executar($caso, $this->autor);

        self::assertCount(1, $detalhe->historico);
        self::assertCount(1, $detalhe->qualificacoes);
    }

    private function qualificacao(CasoCobranca $caso, int $id, QualificacaoContato $qualificacao, string $ocorridoEm): EventoHistorico
    {
        return $this->evento($caso, $id, TipoEventoHistorico::QualificacaoContato, $ocorridoEm)
            ->setDescricao($qualificacao->label())
            ->setDados(['qualificacao' => $qualificacao->value]);
    }

    private function evento(CasoCobranca $caso, int $id, TipoEventoHistorico $tipo, string $ocorridoEm): EventoHistorico
    {
        $evento = (new EventoHistorico())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setTipo($tipo)
            ->setUsuario($this->autor)
            ->setDescricao('Evento ' . $id)
            ->setOcorridoEm(new \DateTimeImmutable($ocorridoEm));
        (new \ReflectionProperty(EventoHistorico::class, 'id'))->setValue($evento, $id);

        return $evento;
    }

    /**
     * @param list<string> $substituidas descrições das obrigações que o acordo substituiu (viram
     *                                    `Obrigacao::acordoSubstituto`)
     * @param list<string> $parcelas     descrições das parcelas geradas pelo acordo (viram
     *                                   `Obrigacao::acordoOrigem`)
     */
    private function casoComAcordoVigente(array $substituidas, array $parcelas): CasoCobranca
    {
        $caso = $this->casoPersistido();

        $acordo = (new Acordo())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setStatus(StatusAcordo::Ativo)
            ->setDataAcordo(new \DateTimeImmutable('2026-03-01'));
        (new \ReflectionProperty(Acordo::class, 'id'))->setValue($acordo, 1);

        $obrigacoes = [];
        $id = 200;
        foreach ($substituidas as $descricao) {
            $obrigacoes[] = $this->novaObrigacao($caso, $id++, 120000, $descricao)
                ->setAcordoSubstituto($acordo);
        }
        foreach ($parcelas as $descricao) {
            $obrigacoes[] = $this->novaObrigacao($caso, $id++, 60000, $descricao)
                ->setAcordoOrigem($acordo);
        }

        $this->obrigacaoRepository->method('doCaso')->willReturn($obrigacoes);
        $this->acordoRepository->method('doCaso')->willReturn([$acordo]);

        return $caso;
    }

    /**
     * Espelho do `casoComAcordoVigente` para o acordo ROMPIDO. As duas diferenças que importam:
     * o acordo não é vigente (então `substituidaPorAcordo` é FALSE e a original volta para a lista
     * solta, exatamente como `doCasoExigiveis` a devolve ao saldo) e as parcelas viram
     * `parcelaDeAcordoDesfeito`, caindo na lista solta junto — que é a origem do dobro.
     *
     * @param list<string> $parcelas
     */
    private function casoComAcordoRompido(string $original, array $parcelas): CasoCobranca
    {
        $caso = $this->casoPersistido();

        $acordo = (new Acordo())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setStatus(StatusAcordo::Rompido)
            ->setDataAcordo(new \DateTimeImmutable('2026-03-01'));
        (new \ReflectionProperty(Acordo::class, 'id'))->setValue($acordo, 1);

        $obrigacoes = [
            $this->novaObrigacao($caso, 200, 120000, $original, '2026-09-01')
                ->setAcordoSubstituto($acordo)
                ->definirEncargos(2000, 1000, 0, 24000, new \DateTimeImmutable(self::AGORA)),
        ];

        $id = 300;
        foreach ($parcelas as $descricao) {
            // Vencimento ANTERIOR ao da original: sem a exclusão, a parcela morta venceria a escolha da
            // competência mais antiga na prescrição, e o teste acima acusaria.
            $obrigacoes[] = $this->novaObrigacao($caso, $id++, 60000, $descricao, '2026-04-01')
                ->setAcordoOrigem($acordo)
                ->definirEncargos(500, 0, 0, 10000, new \DateTimeImmutable(self::AGORA));
        }

        $this->obrigacaoRepository->method('doCaso')->willReturn($obrigacoes);
        $this->acordoRepository->method('doCaso')->willReturn([$acordo]);

        return $caso;
    }

    private function novaObrigacao(CasoCobranca $caso, int $id, int $valorOriginal, ?string $descricao = null, string $vencimento = '2026-09-01'): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao($descricao ?? 'Obrigação '.$id)
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);

        return $o;
    }

    /** @param Carteira|null $carteira Sem carteira, o objeto degrada para config neutra (SemPercentual). */
    private function casoPersistido(?Carteira $carteira = null): CasoCobranca
    {
        $objeto = new ObjetoCobranca();
        if ($carteira !== null) {
            $objeto->setCarteira($carteira);
        }
        (new \ReflectionProperty(ObjetoCobranca::class, 'id'))->setValue($objeto, 77);

        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto($objeto);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);

        return $caso;
    }
}
