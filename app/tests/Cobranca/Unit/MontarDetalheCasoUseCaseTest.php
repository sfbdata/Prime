<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
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
        );

        $this->tenant = new Tenant();
    }

    #[Test]
    public function carrega_as_alocacoes_em_uma_unica_query(): void
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
    public function aloca_o_valor_da_chave_certa_em_cada_obrigacao(): void
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

    #[Test]
    public function grupo_do_acordo_carrega_as_obrigacoes_que_ele_substituiu(): void
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
    public function valor_total_do_grupo_continua_somando_so_as_parcelas_vivas(): void
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
