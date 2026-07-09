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
use App\Cobranca\Service\CalculadoraSaldo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        );
    }

    #[Test]
    public function saldoExigivelSomaValorOriginalMaisEncargosEmCentavos(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('doCasoExigiveis')
            ->with($caso)
            ->willReturn([
                $this->obrigacao(10000, 500, 'now'),   // 10500
                $this->obrigacao(20000, 0, 'now'),      // 20000
            ]);

        // Sem pagamentos nem liquidações (mocks devolvem 0 por padrão).
        self::assertSame(30500, $this->sut->saldoExigivel($caso));
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
        $caso = new CasoCobranca();
        $caso->setTenant($this->createStub(\App\Entity\Tenant\Tenant::class));
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacaoComId(1, 30000, 0, 'now'),
            $this->obrigacaoComId(2, 20000, 0, 'now'),
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
        $caso = new CasoCobranca();
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacao(10000, 0, '-1 day'),   // vencida → conta
            $this->obrigacao(20000, 0, '+1 day'),   // a vencer → NÃO conta
            $this->obrigacao(3000, 200, 'today'),   // vence hoje → conta (3200)
        ]);

        $hoje = new \DateTimeImmutable('today');

        self::assertSame(13200, $this->sut->saldoVencido($caso, $hoje));
    }

    #[Test]
    public function saldoVencidoAbatePagamentoDasVencidasELiquidacaoComPiso(): void
    {
        $caso = new CasoCobranca();
        $caso->setTenant($this->createStub(\App\Entity\Tenant\Tenant::class));
        $vencidaA = $this->obrigacaoComId(1, 10000, 0, '-2 day');
        $vencidaB = $this->obrigacaoComId(2, 5000, 0, '-1 day');
        $aVencer = $this->obrigacaoComId(3, 20000, 0, '+1 day');
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$vencidaA, $vencidaB, $aVencer]);

        // Vencido bruto = 15000; pagamento às vencidas = 4000; liquidação = 2000 → 9000.
        $this->alocacaoRepository
            ->method('totalAlocadoEmObrigacoes')
            ->with([1, 2], $caso->getTenant())
            ->willReturn(4000);
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->willReturn(2000);

        self::assertSame(9000, $this->sut->saldoVencido($caso, new \DateTimeImmutable('today')));
    }

    #[Test]
    public function saldoVencidoNuncaFicaNegativo(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([
            $this->obrigacao(5000, 0, '-1 day'),
        ]);
        // Liquidação reconhecida maior que o vencido → piso 0 (nunca negativo).
        $this->liquidacaoRepository->method('totalReconhecidoNoCaso')->willReturn(9000);

        self::assertSame(0, $this->sut->saldoVencido($caso, new \DateTimeImmutable('today')));
    }

    #[Test]
    public function saldoConsolidadoSomaExigivelDeTodosOsCasosAtivosDoObjeto(): void
    {
        $objeto = new ObjetoCobranca();
        $casoA = new CasoCobranca();
        $casoB = new CasoCobranca();

        $this->casoRepository
            ->expects($this->once())
            ->method('casosAtivosDoObjeto')
            ->with($objeto)
            ->willReturn([$casoA, $casoB]);

        $this->obrigacaoRepository
            ->method('doCasoExigiveis')
            ->willReturnCallback(fn (CasoCobranca $c): array => match ($c) {
                $casoA => [$this->obrigacao(10000, 0, 'now')],
                $casoB => [$this->obrigacao(5000, 250, 'now')],
                default => [],
            });

        // 10000 + 5250 = 15250 — nenhum caso isolado representa o total do objeto.
        self::assertSame(15250, $this->sut->saldoConsolidadoObjeto($objeto));
    }

    private function obrigacao(int $valorOriginal, int $encargos, string $vencimento): Obrigacao
    {
        $obrigacao = new Obrigacao();
        $obrigacao->setValorOriginal($valorOriginal);
        $obrigacao->setEncargosReconhecidos($encargos);
        $obrigacao->setVencimentoOriginal(new \DateTimeImmutable($vencimento));

        return $obrigacao;
    }

    private function obrigacaoComId(int $id, int $valorOriginal, int $encargos, string $vencimento): Obrigacao
    {
        $obrigacao = $this->obrigacao($valorOriginal, $encargos, $vencimento);
        $ref = new \ReflectionProperty(Obrigacao::class, 'id');
        $ref->setValue($obrigacao, $id);

        return $obrigacao;
    }
}
