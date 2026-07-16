<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
        $this->eventoRepository->method('doCaso')->willReturn([]);
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

        // CalculadoraHonorarios também é `final` — e é uma calculadora PURA (sem dependências, sem I/O):
        // a instância real é a fonte única do gross-up, e mocká-la só esconderia a regra sob teste.
        $this->calculadoraHonorarios = new CalculadoraHonorarios();

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
        );

        $this->tenant = new Tenant();
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
        $caso = $this->casoPersistido()
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('10.00');

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
        $calculadora = new CalculadoraHonorarios();
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

    private function novaObrigacao(CasoCobranca $caso, int $id, int $valorOriginal, ?string $descricao = null): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao($descricao ?? 'Obrigação '.$id)
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-09-01'));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);

        return $o;
    }

    private function casoPersistido(): CasoCobranca
    {
        $objeto = new ObjetoCobranca();
        (new \ReflectionProperty(ObjetoCobranca::class, 'id'))->setValue($objeto, 77);

        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto($objeto);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);

        return $caso;
    }
}
