<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Modelo "encargos ao vivo" (spec §6.2, INV-V3): os métodos de I/O do saldo — que carregam obrigações
 * do repositório — HIDRATAM os encargos das VIVAS para HOJE (relógio fixo) antes de somar, de modo que
 * exibição e saldo leem o mesmo número vivo. Obrigação congelada (Liquidada/Substituída) mantém o
 * snapshot. Os métodos PUROS (`derivarSaldos`/`derivarSaldosDosCasos`) recebem obrigações já carregadas
 * e apenas leem `valorExigivel()` — não hidratam (é o Dashboard/quem carrega que hidrata antes).
 *
 * Relógio fixo em 20/07/2026 via `MockClock` (a partir de `DateTimeImmutable`, nunca de string, para o
 * `diff` de dias não escorregar de fuso). Config resolvida do próprio caso (juros 1% a.m., multa 2%
 * sobre o principal) — os valores literais de exigível são derivados dessa config sob o relógio fixo.
 */
#[CoversClass(CalculadoraSaldo::class)]
final class CalculadoraSaldoTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private CasoCobrancaRepository&MockObject $casoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private LiquidacaoRepository&MockObject $liquidacaoRepository;
    private CalculadoraSaldo $sut;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        $this->sut = new CalculadoraSaldo(
            $this->obrigacaoRepository,
            $this->casoRepository,
            $this->alocacaoRepository,
            $this->liquidacaoRepository,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos()),
            new ResolvedorConfigEncargos(),
        );
    }

    #[Test]
    public function saldoExigivelHidrataObrigacoesVivasESomaOExigivelVivo(): void
    {
        $caso = $this->casoTopLife();
        // Vencidas em 20/06/2026 → 30 dias de atraso em 20/07/2026 (relógio fixo).
        // P=100,00 → juros 1,00 + multa 2,00 = exigível 103,00; P=200,00 → 2,00 + 4,00 = 206,00.
        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('doCasoExigiveis')
            ->with($caso)
            ->willReturn([
                $this->obrigacaoViva(10000, '2026-06-20'),
                $this->obrigacaoViva(20000, '2026-06-20'),
            ]);

        // Sem pagamentos nem liquidações (mocks devolvem 0 por padrão).
        self::assertSame(30900, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoExigivelNaoRecalculaCongeladaEUsaOSnapshot(): void
    {
        $caso = $this->casoTopLife();
        $congelada = $this->obrigacaoViva(10000, '2026-06-20')
            ->definirEncargos(999, 111, 0, 222, new \DateTimeImmutable('2026-02-01'))
            ->congelarEncargos(new \DateTimeImmutable('2026-02-01'));
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$congelada]);

        // Snapshot: 10000 + 999 + 111 + 0 = 11110 (a hidratação NÃO toca a congelada).
        self::assertSame(11110, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoExigivelDeCasoSemObrigacoesEZero(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);

        self::assertSame(0, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoExigivelSubtraiPagamentosAlocadosELiquidacoes(): void
    {
        $caso = $this->casoTopLife();
        $caso->setTenant($this->createStub(\App\Entity\Tenant\Tenant::class));
        // Vencendo HOJE (20/07/2026) → 0 dias → 0 encargos: exigível = valor original.
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoViva(30000, '2026-07-20', 1),
            $this->obrigacaoViva(20000, '2026-07-20', 2),
        ]);
        // Bruto 50000; pagamentos alocados às obrigações exigíveis 12000; liquidação 8000 → 30000.
        $this->alocacaoRepository
            ->method('totalAlocadoEmObrigacoes')
            ->with([1, 2], $caso->getTenant())
            ->willReturn(12000);
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->with($caso)->willReturn(8000);

        self::assertSame(30000, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoVencidoContaSomenteObrigacoesVencidasAteHoje(): void
    {
        $caso = $this->casoTopLife();
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoViva(10000, '2026-06-20'),   // vencida (30 dias) → conta 10300
            $this->obrigacaoViva(20000, '2026-08-20'),   // a vencer → NÃO conta
            $this->obrigacaoViva(3000, '2026-07-20'),    // vence hoje → conta 3000 (0 encargos)
        ]);

        $hoje = new \DateTimeImmutable('2026-07-20');

        self::assertSame(13300, $this->sut->saldoVencido($caso, $hoje));
    }

    #[Test]
    public function saldoVencidoAbatePagamentoDasVencidasELiquidacaoComPiso(): void
    {
        $caso = $this->casoTopLife();
        $caso->setTenant($this->createStub(\App\Entity\Tenant\Tenant::class));
        // Vencendo hoje → 0 encargos, para isolar a aritmética de abate.
        $vencidaA = $this->obrigacaoViva(10000, '2026-07-20', 1);
        $vencidaB = $this->obrigacaoViva(5000, '2026-07-20', 2);
        $aVencer = $this->obrigacaoViva(20000, '2026-08-20', 3);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$vencidaA, $vencidaB, $aVencer]);

        // Vencido bruto = 15000; pagamento às vencidas = 4000; liquidação = 2000 → 9000.
        $this->alocacaoRepository
            ->method('totalAlocadoEmObrigacoes')
            ->with([1, 2], $caso->getTenant())
            ->willReturn(4000);
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->willReturn(2000);

        self::assertSame(9000, $this->sut->saldoVencido($caso, new \DateTimeImmutable('2026-07-20')));
    }

    #[Test]
    public function saldoVencidoNuncaFicaNegativo(): void
    {
        $caso = $this->casoTopLife();
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoViva(5000, '2026-07-20'),
        ]);
        // Liquidação reconhecida maior que o vencido → piso 0 (nunca negativo).
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->willReturn(9000);

        self::assertSame(0, $this->sut->saldoVencido($caso, new \DateTimeImmutable('2026-07-20')));
    }

    #[Test]
    public function saldoConsolidadoSomaExigivelVivoDeTodosOsCasosAtivosDoObjeto(): void
    {
        $objeto = new ObjetoCobranca();
        $casoA = $this->casoTopLife();
        $casoB = $this->casoTopLife();

        $this->casoRepository
            ->expects($this->once())
            ->method('casosAtivosDoObjeto')
            ->with($objeto)
            ->willReturn([$casoA, $casoB]);

        $this->obrigacaoRepository
            ->method('doCasoExigiveis')
            ->willReturnCallback(fn (CasoCobranca $c): array => match ($c) {
                $casoA => [$this->obrigacaoViva(10000, '2026-07-20')],   // 0 encargos → 10000
                $casoB => [$this->obrigacaoViva(5000, '2026-06-20')],    // 30 dias → 5150
                default => [],
            });

        // 10000 + 5150 = 15150 — nenhum caso isolado representa o total do objeto.
        self::assertSame(15150, $this->sut->saldoConsolidadoObjeto($objeto));
    }

    #[Test]
    public function saldosDosCasosHidrataCadaCasoEmLoteAntesDeDerivar(): void
    {
        $tenant = $this->createStub(\App\Entity\Tenant\Tenant::class);
        $casoA = $this->casoTopLifeComId(1);
        $casoB = $this->casoTopLifeComId(2);

        // Vencidas em 20/06 (30 dias): casoA P=100,00 → 103,00; casoB P=50,00 → 51,50.
        $oA = $this->obrigacaoViva(10000, '2026-06-20', 10);
        $oA->setCaso($casoA);
        $oB = $this->obrigacaoViva(5000, '2026-06-20', 20);
        $oB->setCaso($casoB);

        $this->obrigacaoRepository
            ->method('exigiveisDosCasos')
            ->with([1, 2], $tenant)
            ->willReturn([$oA, $oB]);
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $this->liquidacaoRepository->method('dosCasos')->willReturn([]);

        $saldos = $this->sut->saldosDosCasos([$casoA, $casoB], $tenant, new \DateTimeImmutable('2026-07-20'));

        self::assertSame(10300, $saldos[1]['exigivel']);
        self::assertSame(5150, $saldos[2]['exigivel']);
    }

    #[Test]
    public function derivarSaldosEspelhaExigivelEVencidoComAlocacaoELiquidacao(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $vencidaA = $this->obrigacaoComEncargos(1, 10000, 2000, '-2 day'); // exigível 12000, vencida
        $vencidaB = $this->obrigacaoComEncargos(2, 5000, 0, '-1 day');     // exigível 5000, vencida
        $aVencer = $this->obrigacaoComEncargos(3, 20000, 0, '+1 day');     // exigível 20000, a vencer

        // Alocado só às vencidas; liquidação do caso = 2000.
        $saldos = $this->sut->derivarSaldos([$vencidaA, $vencidaB, $aVencer], [1 => 3000, 2 => 1000], 2000, $hoje);

        // exigível = (12000+5000+20000) − (3000+1000) − 2000 = 31000
        self::assertSame(31000, $saldos['exigivel']);
        // vencido = (12000+5000) − (3000+1000) − 2000 = 11000
        self::assertSame(11000, $saldos['vencido']);
    }

    #[Test]
    public function derivarSaldosVencidoTemPisoZeroEExigivelPodeSerNegativo(): void
    {
        $hoje = new \DateTimeImmutable('today');
        $vencida = $this->obrigacaoComEncargos(1, 5000, 0, '-1 day');

        // Over-liquidação: liquidado (9000) > exigível (5000).
        $saldos = $this->sut->derivarSaldos([$vencida], [], 9000, $hoje);

        self::assertSame(-4000, $saldos['exigivel']); // exigível sem piso (fiel à regra por-caso)
        self::assertSame(0, $saldos['vencido']);       // vencido com piso 0
    }

    /** Caso com config TOPLIFE (juros 1% a.m., multa 2% sobre o principal) resolvida do próprio caso. */
    private function casoTopLife(): CasoCobranca
    {
        $caso = new CasoCobranca();
        $caso->setTaxaJurosMensalBp(100);
        $caso->setTaxaMultaBp(200);

        return $caso;
    }

    private function casoTopLifeComId(int $id): CasoCobranca
    {
        $caso = $this->casoTopLife();
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, $id);

        return $caso;
    }

    /** Obrigação VIVA (sem encargos materializados): a hidratação os calcula sob o relógio fixo. */
    private function obrigacaoViva(int $valorOriginal, string $venc, ?int $id = null): Obrigacao
    {
        $obrigacao = (new Obrigacao())
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($venc));

        if ($id !== null) {
            (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, $id);
        }

        return $obrigacao;
    }

    /**
     * Obrigação com encargos JÁ materializados e com id — usada só nos testes dos métodos PUROS
     * (`derivarSaldos`), que não hidratam: leem o `valorExigivel()` como veio.
     */
    private function obrigacaoComEncargos(int $id, int $valorOriginal, int $encargos, string $vencimento): Obrigacao
    {
        $obrigacao = (new Obrigacao())
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento));
        $obrigacao->setEncargosReconhecidos($encargos);
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, $id);

        return $obrigacao;
    }
}
