<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\CasoCobrancaRepository;
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
    private CalculadoraSaldo $sut;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->sut = new CalculadoraSaldo($this->obrigacaoRepository, $this->casoRepository);
    }

    #[Test]
    public function saldoExigivelSomaValorOriginalMaisEncargosEmCentavos(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository
            ->expects($this->once())
            ->method('doCaso')
            ->with($caso)
            ->willReturn([
                $this->obrigacao(10000, 500, 'now'),   // 10500
                $this->obrigacao(20000, 0, 'now'),      // 20000
            ]);

        self::assertSame(30500, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoExigivelDeCasoSemObrigacoesEZero(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository->method('doCaso')->willReturn([]);

        self::assertSame(0, $this->sut->saldoExigivel($caso));
    }

    #[Test]
    public function saldoVencidoContaSomenteObrigacoesVencidasAteHoje(): void
    {
        $caso = new CasoCobranca();
        $this->obrigacaoRepository->method('doCaso')->willReturn([
            $this->obrigacao(10000, 0, '-1 day'),   // vencida → conta
            $this->obrigacao(20000, 0, '+1 day'),   // a vencer → NÃO conta
            $this->obrigacao(3000, 200, 'today'),   // vence hoje → conta (3200)
        ]);

        $hoje = new \DateTimeImmutable('today');

        self::assertSame(13200, $this->sut->saldoVencido($caso, $hoje));
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
            ->method('doCaso')
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
}
