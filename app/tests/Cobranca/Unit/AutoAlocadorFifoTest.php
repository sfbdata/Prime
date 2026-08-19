<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Exception\PagamentoExcedeSaldoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Auto-alocação FIFO (Ajuste 6): a partir do valor pago (bruto), separa a parte-dívida via
 * CalculadoraHonorarios e a distribui pelas obrigações EXIGÍVEIS na ordem em que `doCasoExigiveis`
 * as entrega (vencimentoOriginal ASC — mais antiga primeiro). O teto é o saldo exigível derivado
 * (abate alocações E liquidação por-caso); acima dele, bloqueia (decisão D1). Correção exclui as
 * alocações do próprio pagamento sendo reescrito.
 */
#[CoversClass(AutoAlocadorFifo::class)]
final class AutoAlocadorFifoTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private LiquidacaoRepository&MockObject $liquidacaoRepository;
    private AutoAlocadorFifo $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        // CalculadoraHonorarios, EncargosVivos e ResolvedorConfigEncargos são finais e puros: usam-se
        // as instâncias REAIS. Os casos de teste não têm carteira/config → a hidratação recalcula
        // encargo 0 (no-op sobre o exigível); honorários ficam fora do exigível, então não movem o FIFO.
        $this->sut = new AutoAlocadorFifo(
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            $this->liquidacaoRepository,
            new CalculadoraHonorarios(new ResolvedorConfigEncargos()),
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
        $this->tenant = new Tenant();
    }

    #[Test]
    public function distribuiFifoDaMaisAntigaParaMaisNova(): void
    {
        // sem_percentual: dívida == valor pago. 3 obrigações de 40000, paga 100000.
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 40000, 0, '-3 day'),
            $this->obrigacaoComId(11, 40000, 0, '-2 day'),
            $this->obrigacaoComId(12, 40000, 0, '-1 day'),
        ]);

        $previa = $this->sut->derivar($caso, 100000, $this->tenant);

        self::assertFalse($previa->excede);
        self::assertSame(100000, $previa->valorDivida);
        self::assertSame(0, $previa->valorHonorarios);
        // Enche a mais antiga (10) e a seguinte (11); a última (12) leva o resíduo (20000).
        self::assertCount(3, $previa->linhas);
        self::assertSame([10, 40000], [$previa->linhas[0]->obrigacaoId, $previa->linhas[0]->valor]);
        self::assertSame([11, 40000], [$previa->linhas[1]->obrigacaoId, $previa->linhas[1]->valor]);
        self::assertSame([12, 20000], [$previa->linhas[2]->obrigacaoId, $previa->linhas[2]->valor]);
    }

    #[Test]
    public function distribuiOValorCheioSemCarvarHonorarioFora(): void
    {
        // Era `acrescidoDividaSeparaHonorariosAntesDeAlocar`: de 110000 só 100000 eram distribuídos.
        // O rateio SAIU (spec `cobranca-honorario-no-total.md` §4.3): com o honorário dentro do
        // exigível, carvá-lo fora faria o FIFO nunca cobrir a dívida.
        $carteira = (new Carteira())->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)->setPercentualHonorarios('10.00');
        $caso = $this->casoComId(1)->setObjeto((new ObjetoCobranca())->setCarteira($carteira));
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 60000, 0, '-2 day'),
            $this->obrigacaoComId(11, 60000, 0, '-1 day'),
        ]);

        $previa = $this->sut->derivar($caso, 110000, $this->tenant);

        self::assertFalse($previa->excede);
        self::assertSame(110000, $previa->valorDivida);
        self::assertSame(0, $previa->valorHonorarios);
        self::assertSame(110000, array_sum(array_map(static fn ($l) => $l->valor, $previa->linhas)));
        self::assertSame([10, 60000], [$previa->linhas[0]->obrigacaoId, $previa->linhas[0]->valor]);
        self::assertSame([11, 50000], [$previa->linhas[1]->obrigacaoId, $previa->linhas[1]->valor]);
    }

    #[Test]
    public function respeitaAlocacaoJaExistentePorObrigacao(): void
    {
        // Obrigação 10 já tem 30000 alocados (sala 20000); obrigação 11 intacta (sala 50000).
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 50000, 0, '-2 day'),
            $this->obrigacaoComId(11, 50000, 0, '-1 day'),
        ]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->with([1], $this->tenant)->willReturn([10 => 30000]);

        $previa = $this->sut->derivar($caso, 60000, $this->tenant);

        self::assertFalse($previa->excede);
        self::assertSame([10, 20000], [$previa->linhas[0]->obrigacaoId, $previa->linhas[0]->valor]);
        self::assertSame([11, 40000], [$previa->linhas[1]->obrigacaoId, $previa->linhas[1]->valor]);
    }

    #[Test]
    public function liquidacaoReduzOTetoEBloqueiaAcimaDoSaldo(): void
    {
        // Sala bruta 100000; liquidação 30000 → saldo disponível 70000. Paga 80000 → excede em 10000.
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 50000, 0, '-2 day'),
            $this->obrigacaoComId(11, 50000, 0, '-1 day'),
        ]);
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->with($caso)->willReturn(30000);

        $previa = $this->sut->derivar($caso, 80000, $this->tenant);

        self::assertTrue($previa->excede);
        self::assertSame(70000, $previa->saldoDisponivel);
        self::assertSame(10000, $previa->excedeEm);
        self::assertSame([], $previa->linhas);
    }

    #[Test]
    public function sobrepagamentoBloqueiaComExcedeEm(): void
    {
        // Sala total 50000; paga 60000 (sem percentual) → excede em 10000, sem linhas.
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 50000, 0, '-1 day'),
        ]);

        $previa = $this->sut->derivar($caso, 60000, $this->tenant);

        self::assertTrue($previa->excede);
        self::assertSame(50000, $previa->saldoDisponivel);
        self::assertSame(10000, $previa->excedeEm);
        self::assertSame([], $previa->linhas);
    }

    #[Test]
    public function tetoEspelhaSaldoExigivelSobSuperAlocacao(): void
    {
        // Obrigação 10 (exigível 50000) foi super-alocada no modo manual (60000). Saldo exigível real
        // = 100000 − 60000 = 40000. O teto NÃO pode usar max(0,…) por obrigação (daria 50000 e
        // liberaria um pagamento que zera o saldo real p/ negativo — quebra D1).
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 50000, 0, '-2 day'),
            $this->obrigacaoComId(11, 50000, 0, '-1 day'),
        ]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([10 => 60000]);

        // Paga 50000: excederia o saldo real (40000) → bloqueia em 10000.
        $previa = $this->sut->derivar($caso, 50000, $this->tenant);
        self::assertTrue($previa->excede);
        self::assertSame(40000, $previa->saldoDisponivel);
        self::assertSame(10000, $previa->excedeEm);

        // Paga 40000 (== saldo real): passa e aloca só na obrigação com sala (11), sem estourar.
        $ok = $this->sut->derivar($caso, 40000, $this->tenant);
        self::assertFalse($ok->excede);
        self::assertSame([11, 40000], [$ok->linhas[0]->obrigacaoId, $ok->linhas[0]->valor]);
        self::assertSame(40000, array_sum(array_map(static fn ($l) => $l->valor, $ok->linhas)));
    }

    #[Test]
    public function semObrigacoesExigiveisBloqueia(): void
    {
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);

        $previa = $this->sut->derivar($caso, 10000, $this->tenant);

        self::assertTrue($previa->excede);
        self::assertSame(0, $previa->saldoDisponivel);
        self::assertSame([], $previa->linhas);
    }

    #[Test]
    public function alocarRetornaInputsQuandoCabe(): void
    {
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 40000, 0, '-2 day'),
            $this->obrigacaoComId(11, 40000, 0, '-1 day'),
        ]);

        $inputs = $this->sut->alocar($caso, 60000, $this->tenant);

        self::assertCount(2, $inputs);
        self::assertSame(10, $inputs[0]->obrigacaoId);
        self::assertSame(40000, $inputs[0]->valor);
        self::assertSame(11, $inputs[1]->obrigacaoId);
        self::assertSame(20000, $inputs[1]->valor);
        self::assertSame(60000, array_sum(array_map(static fn ($i) => $i->valor, $inputs)));
    }

    #[Test]
    public function alocarLancaExcecaoQuandoExcede(): void
    {
        $caso = $this->casoComId(1);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 50000, 0, '-1 day'),
        ]);

        $this->expectException(PagamentoExcedeSaldoException::class);

        $this->sut->alocar($caso, 60000, $this->tenant);
    }

    #[Test]
    public function correcaoIgnoraAlocacoesDoProprioPagamento(): void
    {
        // Obrigação 10 tem 30000 alocados, mas TODOS são do pagamento em correção → sala volta a 50000.
        // Sem a exclusão, a sala total seria 70000 < 100000 e bloquearia por engano.
        $caso = $this->casoComId(1);
        $obrig10 = $this->obrigacaoComId(10, 50000, 0, '-2 day');
        $obrig11 = $this->obrigacaoComId(11, 50000, 0, '-1 day');
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$obrig10, $obrig11]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([10 => 30000]);

        $pagamento = new Pagamento();
        $alocacao = (new AlocacaoPagamento())->setObrigacao($obrig10)->setValor(30000);
        $pagamento->adicionarAlocacao($alocacao);

        $previa = $this->sut->derivar($caso, 100000, $this->tenant, null, $pagamento);

        self::assertFalse($previa->excede);
        self::assertSame([10, 50000], [$previa->linhas[0]->obrigacaoId, $previa->linhas[0]->valor]);
        self::assertSame([11, 50000], [$previa->linhas[1]->obrigacaoId, $previa->linhas[1]->valor]);
    }

    #[Test]
    public function derivarMedeOExigivelNaDataDoPagamentoNaoEmHoje(): void
    {
        // Caso TOPLIFE (juros 1% a.m., multa 2%): obrigação P=100,00, venc 10/06/2026. T1: o override
        // mora no OBJETO — o caso deixou de participar da cascata.
        $caso = $this->casoComId(1);
        $objeto = (new ObjetoCobranca())->setTaxaJurosMensalBp(100)->setTaxaMultaBp(200);
        $caso->setObjeto($objeto);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(10, 10000, 0, '2026-06-10'),
        ]);

        // Data do pagamento = 10/07 (30 dias de atraso): exigível = 100,00 + juros 1,00 + multa 2,00 = 103,00.
        // O pagamento de 103,00 CABE exato — mede a dívida na data do pagamento, não em "hoje".
        $em10jul = $this->sut->derivar($caso, 10300, $this->tenant, new \DateTimeImmutable('2026-07-10'));
        self::assertFalse($em10jul->excede);
        self::assertSame(10300, $em10jul->saldoDisponivel, 'exigível medido em 10/07 (data do pagamento)');

        // Sem data (prévia = "agora" = MockClock 20/07, 40 dias): exigível maior (juros 1,33) = 103,33.
        // Prova que a data do pagamento — e não o relógio — rege a medição da dívida no FIFO.
        $emHoje = $this->sut->derivar($caso, 10300, $this->tenant);
        self::assertSame(10333, $emHoje->saldoDisponivel, 'exigível ao vivo em 20/07 é maior que em 10/07');
    }

    private function casoComId(int $id): CasoCobranca
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, $id);

        return $caso;
    }

    private function obrigacaoComId(int $id, int $valorOriginal, int $encargos, string $vencimento): Obrigacao
    {
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setDescricao('Obrigação '.$id)
            ->setValorOriginal($valorOriginal)
            ->setEncargosReconhecidos($encargos)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, $id);

        return $obrigacao;
    }
}
