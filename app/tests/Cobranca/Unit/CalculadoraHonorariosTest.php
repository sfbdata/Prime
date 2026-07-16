<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Service\CalculadoraHonorarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalculadoraHonorarios::class)]
final class CalculadoraHonorariosTest extends TestCase
{
    private CalculadoraHonorarios $sut;

    protected function setUp(): void
    {
        $this->sut = new CalculadoraHonorarios();
    }

    // ---- projetados -------------------------------------------------------

    #[Test]
    public function projetadosAplicaOPercentualSobreABase(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        // 10% de R$1.000,00 (100000 centavos) = R$100,00.
        self::assertSame(10000, $this->sut->projetados($caso, 100000));
    }

    #[Test]
    public function projetadosArredondaMeioParaCimaEmCentavos(): void
    {
        $caso = $this->caso(FormaHonorarios::RetidoRecuperado, '12.50');

        // 12,5% de 1234 = 154,25 → 154 (arredonda para baixo, < 0,5).
        self::assertSame(154, $this->sut->projetados($caso, 1234));
        // 12,5% de 1236 = 154,50 → 155 (meio para cima).
        self::assertSame(155, $this->sut->projetados($caso, 1236));
    }

    #[Test]
    public function projetadosSemPercentualEZero(): void
    {
        $caso = $this->caso(FormaHonorarios::SemPercentual, null);

        self::assertSame(0, $this->sut->projetados($caso, 100000));
    }

    #[Test]
    public function projetadosComBaseNaoPositivaEZero(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        self::assertSame(0, $this->sut->projetados($caso, 0));
        self::assertSame(0, $this->sut->projetados($caso, -5000));
    }

    #[Test]
    public function projetadosComPercentualNuloEZero(): void
    {
        // Forma exige percentual, mas o snapshot não tem percentual configurado.
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, null);

        self::assertSame(0, $this->sut->projetados($caso, 100000));
    }

    // ---- ratearPagamento --------------------------------------------------

    #[Test]
    public function rateiaPagamentoAcrescidoEntreDividaEHonorarios(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        // Total R$11,00 = dívida R$10,00 + honorários R$1,00 (10% acrescido).
        self::assertSame([1000, 100], $this->sut->ratearPagamento($caso, 1100));
    }

    #[Test]
    public function rateioDeDizimaFechaExatamenteEmCentavos(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        // 1000 → honorários = 1000·(0,10/1,10) = 90,9... → 91; dívida = 909; soma = 1000.
        [$divida, $honorarios] = $this->sut->ratearPagamento($caso, 1000);

        self::assertSame(91, $honorarios);
        self::assertSame(909, $divida);
        self::assertSame(1000, $divida + $honorarios);
    }

    /**
     * @param non-empty-string $percentual
     */
    #[Test]
    #[DataProvider('cenariosDeFechamento')]
    public function rateioSempreFechaComOTotal(string $percentual, int $total): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, $percentual);

        [$divida, $honorarios] = $this->sut->ratearPagamento($caso, $total);

        self::assertSame($total, $divida + $honorarios, 'Rateio deve fechar exatamente com o total.');
        self::assertGreaterThanOrEqual(0, $divida);
        self::assertGreaterThanOrEqual(0, $honorarios);
    }

    /**
     * @return iterable<string, array{0:string,1:int}>
     */
    public static function cenariosDeFechamento(): iterable
    {
        yield '10% de 1' => ['10.00', 1];
        yield '10% de 333' => ['10.00', 333];
        yield '10% de 1000' => ['10.00', 1000];
        yield '15% de 99999' => ['15.00', 99999];
        yield '33.33% de 123457' => ['33.33', 123457];
        yield '7.77% de 1000003' => ['7.77', 1000003];
    }

    #[Test]
    public function rateioNaoAcrescidoNaoSeparaHonorarios(): void
    {
        // Retido, cobrado separado e sem percentual: o devedor paga só a dívida.
        foreach ([FormaHonorarios::RetidoRecuperado, FormaHonorarios::CobradoSeparado] as $forma) {
            $caso = $this->caso($forma, '20.00');

            self::assertSame([5000, 0], $this->sut->ratearPagamento($caso, 5000));
        }

        $semPercentual = $this->caso(FormaHonorarios::SemPercentual, null);
        self::assertSame([5000, 0], $this->sut->ratearPagamento($semPercentual, 5000));
    }

    #[Test]
    public function rateioDeValorNaoPositivoNaoSeparaHonorarios(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        self::assertSame([0, 0], $this->sut->ratearPagamento($caso, 0));
    }

    // ---- realizadosSobreRecuperacao --------------------------------------

    #[Test]
    #[DataProvider('cenariosRealizados')]
    public function realizadosPorForma(FormaHonorarios $forma, ?string $percentual, int $recuperado, int $esperado): void
    {
        $caso = $this->caso($forma, $percentual);

        self::assertSame($esperado, $this->sut->realizadosSobreRecuperacao($caso, $recuperado));
    }

    /**
     * @return iterable<string, array{0:FormaHonorarios,1:?string,2:int,3:int}>
     */
    public static function cenariosRealizados(): iterable
    {
        yield 'acrescido 10% de 1000' => [FormaHonorarios::AcrescidoDivida, '10.00', 1000, 100];
        yield 'retido 20% de 5000' => [FormaHonorarios::RetidoRecuperado, '20.00', 5000, 1000];
        yield 'cobrado separado 15% de 2000' => [FormaHonorarios::CobradoSeparado, '15.00', 2000, 300];
        yield 'sem percentual' => [FormaHonorarios::SemPercentual, null, 5000, 0];
        yield 'recuperado zero' => [FormaHonorarios::AcrescidoDivida, '10.00', 0, 0];
    }

    // ---- brutoParaRecuperar -----------------------------------------------

    #[Test]
    public function bruto_para_recuperar_fecha_o_round_trip_do_rateio(): void
    {
        // A propriedade que importa: o bruto sugerido rateia de volta para EXATAMENTE o alvo.
        // Dupla-arredondamento não se valida por inspeção — só por varredura.
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        foreach ([1, 2, 99, 100, 101, 105, 333, 80000, 120000, 199999] as $alvo) {
            $bruto = $this->sut->brutoParaRecuperar($caso, $alvo);
            [$divida, ] = $this->sut->ratearPagamento($caso, $bruto);

            self::assertSame($alvo, $divida, "round-trip falhou para alvo={$alvo}");
        }
    }

    #[Test]
    public function bruto_para_recuperar_acrescenta_os_honorarios_ao_alvo(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        // R$1.200,00 de dívida + 10% => R$1.320,00 de boleto.
        self::assertSame(132000, $this->sut->brutoParaRecuperar($caso, 120000));
    }

    #[Test]
    public function bruto_para_recuperar_devolve_o_alvo_quando_a_forma_nao_acresce(): void
    {
        // Nas outras formas o devedor paga só a dívida — espelha `ratearPagamento`.
        foreach ([FormaHonorarios::RetidoRecuperado, FormaHonorarios::CobradoSeparado, FormaHonorarios::SemPercentual] as $forma) {
            $caso = $this->caso($forma, '10.00');

            self::assertSame(120000, $this->sut->brutoParaRecuperar($caso, 120000));
        }
    }

    #[Test]
    public function bruto_para_recuperar_devolve_o_alvo_quando_nao_ha_percentual(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, null);

        self::assertSame(120000, $this->sut->brutoParaRecuperar($caso, 120000));
    }

    #[Test]
    public function bruto_para_recuperar_devolve_o_alvo_quando_ele_nao_e_positivo(): void
    {
        $caso = $this->caso(FormaHonorarios::AcrescidoDivida, '10.00');

        self::assertSame(0, $this->sut->brutoParaRecuperar($caso, 0));
        self::assertSame(-5, $this->sut->brutoParaRecuperar($caso, -5));
    }

    private function caso(FormaHonorarios $forma, ?string $percentual): CasoCobranca
    {
        $caso = new CasoCobranca();
        $caso->setFormaHonorarios($forma);
        $caso->setPercentualHonorarios($percentual);

        return $caso;
    }
}
