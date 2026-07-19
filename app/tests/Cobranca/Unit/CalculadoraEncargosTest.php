<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Service\CalculadoraEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O motor de encargos é dinheiro: a prova de que ele está certo não é "o código parece razoável", é
 * bater ao CENTAVO contra o extrato real da contabilidade. Os quatro primeiros testes são as quatro
 * linhas reais das planilhas TOPLIFE conferidas por verificação independente (Apêndice A da spec),
 * com erro zero — se um deles quebrar, quem está errado é a fórmula, não o teste.
 *
 * O resto cobre as bordas que os dados reais NÃO observam (e por isso são as mais perigosas): o
 * corte exato da carência, os dois arredondamentos diferentes no empate de meio centavo, as bases
 * compostas, a tolerância de juros/multa e o regime composto.
 */
#[CoversClass(CalculadoraEncargos::class)]
final class CalculadoraEncargosTest extends TestCase
{
    private CalculadoraEncargos $sut;

    protected function setUp(): void
    {
        $this->sut = new CalculadoraEncargos();
    }

    // ---------------------------------------------------------------------------------------
    // Provas contra dados reais (Apêndice A da spec) — 4.413 linhas, 100% ao centavo
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function provaRealToplifeIIComAtrasoDe56Dias(): void
    {
        // P=170,00 · 56 dias · honorários 15% → Jur 3,17 · Mul 3,40 · Hon 26,49 · Total 203,06
        $encargos = $this->calcular(principal: 17000, dias: 56, honorariosBp: 1500);

        self::assertSame(317, $encargos['juros']);
        self::assertSame(340, $encargos['multa']);
        self::assertSame(0, $encargos['correcao']);
        self::assertSame(2649, $encargos['honorarios']);
        self::assertSame(20306, $this->total(17000, $encargos));
    }

    #[Test]
    public function provaRealToplifeIIComAtrasoDe25DiasNaoTemHonorario(): void
    {
        // P=170,00 · 25 dias · 15% → Jur 1,42 · Mul 3,40 · Hon 0,00 · Total 174,82 (dentro da carência)
        $encargos = $this->calcular(principal: 17000, dias: 25, honorariosBp: 1500);

        self::assertSame(142, $encargos['juros']);
        self::assertSame(340, $encargos['multa']);
        self::assertSame(0, $encargos['correcao']);
        self::assertSame(0, $encargos['honorarios'], 'até 30 dias de atraso não há honorário');
        self::assertSame(17482, $this->total(17000, $encargos));
    }

    #[Test]
    public function provaRealToplifeIComAtrasoDe240Dias(): void
    {
        // P=170,00 · 240 dias · honorários 20% → Jur 13,60 · Mul 3,40 · Hon 37,40 · Total 224,40
        $encargos = $this->calcular(principal: 17000, dias: 240, honorariosBp: 2000);

        self::assertSame(1360, $encargos['juros']);
        self::assertSame(340, $encargos['multa'], 'a multa é fixa sobre o principal: não cresce com o atraso');
        self::assertSame(0, $encargos['correcao']);
        self::assertSame(3740, $encargos['honorarios']);
        self::assertSame(22440, $this->total(17000, $encargos));
    }

    #[Test]
    public function provaRealToplifeIComAtrasoDe2889Dias(): void
    {
        // P=50,00 · 2889 dias · 20% → Jur 48,15 · Mul 1,00 · Hon 19,83 · Total 118,98.
        // Juros lineares em ~96% do principal depois de 8 anos: é o que prova o regime SIMPLES
        // (composto teria estourado muito acima disso).
        $encargos = $this->calcular(principal: 5000, dias: 2889, honorariosBp: 2000);

        self::assertSame(4815, $encargos['juros']);
        self::assertSame(100, $encargos['multa']);
        self::assertSame(0, $encargos['correcao']);
        self::assertSame(1983, $encargos['honorarios']);
        self::assertSame(11898, $this->total(5000, $encargos));
    }

    // ---------------------------------------------------------------------------------------
    // Ausência de encargo
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function obrigacaoEmDiaNaoGeraNenhumEncargo(): void
    {
        $encargos = $this->calcular(principal: 17000, dias: 0, honorariosBp: 2000);

        self::assertSame(['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0], $encargos);
    }

    #[Test]
    public function vencimentoNoFuturoNaoGeraEncargo(): void
    {
        $encargos = $this->sut->calcular(
            17000,
            $this->apos(10),
            ConfigEncargos::padraoTopLife(2000),
            $this->vencimento(),
        );

        self::assertSame(['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0], $encargos);
    }

    #[Test]
    public function principalZeradoNaoGeraEncargoMesmoComAtrasoLongo(): void
    {
        $encargos = $this->calcular(principal: 0, dias: 500, honorariosBp: 2000);

        self::assertSame(['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0], $encargos);
    }

    #[Test]
    public function principalNegativoNaoGeraEncargo(): void
    {
        $encargos = $this->calcular(principal: -17000, dias: 500, honorariosBp: 2000);

        self::assertSame(['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0], $encargos);
    }

    #[Test]
    public function configuracaoNeutraNaoGeraEncargoNenhum(): void
    {
        // É o comportamento de HOJE, preservado por default (decisão D4): carteira recém-migrada
        // não pode começar a gerar juros sozinha.
        $encargos = $this->sut->calcular(17000, $this->vencimento(), ConfigEncargos::neutra(), $this->apos(365));

        self::assertSame(['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0], $encargos);
    }

    // ---------------------------------------------------------------------------------------
    // Carência de honorários — corte ESTRITO (a borda que os dados reais não observam)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function noTrigesimoDiaAindaNaoHaHonorario(): void
    {
        $encargos = $this->calcular(principal: 17000, dias: 30, honorariosBp: 1500);

        self::assertSame(170, $encargos['juros']);
        self::assertSame(340, $encargos['multa']);
        self::assertSame(0, $encargos['honorarios'], 'carência 30 ⇒ o 30º dia ainda é isento');
    }

    #[Test]
    public function noTrigesimoPrimeiroDiaOHonorarioAparece(): void
    {
        $encargos = $this->calcular(principal: 17000, dias: 31, honorariosBp: 1500);

        self::assertSame(176, $encargos['juros']);
        self::assertSame(340, $encargos['multa']);
        // base composta = 17000 + 176 + 340 = 17516 · 15% = 2627,4 → 2627
        self::assertSame(2627, $encargos['honorarios']);
    }

    #[Test]
    public function semCarenciaOHonorarioValeDesdeOPrimeiroDia(): void
    {
        $config = new ConfigEncargos(
            taxaJurosMensalBp: 0,
            taxaMultaBp: 0,
            taxaHonorariosBp: 2000,
            baseHonorarios: BaseEncargo::Principal,
            carenciaHonorariosDias: 0,
        );

        $encargos = $this->sut->calcular(17000, $this->vencimento(), $config, $this->apos(1));

        self::assertSame(3400, $encargos['honorarios']);
    }

    // ---------------------------------------------------------------------------------------
    // Tolerância de juros/multa (default 0 — os dados provam que valem do 1º dia)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function dentroDaToleranciaNaoHaJurosNemMulta(): void
    {
        $encargos = $this->sut->calcular(
            17000,
            $this->vencimento(),
            $this->comTolerancia(5),
            $this->apos(5),
        );

        self::assertSame(0, $encargos['juros']);
        self::assertSame(0, $encargos['multa']);
        self::assertSame(0, $encargos['correcao']);
    }

    #[Test]
    public function passandoUmDiaDaToleranciaJurosEMultaEntramInteiros(): void
    {
        $encargos = $this->sut->calcular(
            17000,
            $this->vencimento(),
            $this->comTolerancia(5),
            $this->apos(6),
        );

        // Juros contam desde o vencimento (6 dias), não desde o fim da tolerância.
        self::assertSame(34, $encargos['juros']);
        self::assertSame(340, $encargos['multa'], 'a multa entra inteira, não proporcional');
    }

    // ---------------------------------------------------------------------------------------
    // Arredondamento — os dois lados são DIFERENTES e isso é de propósito
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function jurosArredondamMeioParaBaixoNoEmpateExato(): void
    {
        // P=15,00 a 1% a.m.: cada dia rende exatamente meio centavo.
        $soJuros = new ConfigEncargos(taxaJurosMensalBp: 100);

        // 1 dia = 0,5 centavo → fica embaixo.
        self::assertSame(0, $this->sut->calcular(1500, $this->vencimento(), $soJuros, $this->apos(1))['juros']);
        // 2 dias = 1,0 centavo exato.
        self::assertSame(1, $this->sut->calcular(1500, $this->vencimento(), $soJuros, $this->apos(2))['juros']);
        // 3 dias = 1,5 centavo → fica embaixo (half-up daria 2).
        self::assertSame(1, $this->sut->calcular(1500, $this->vencimento(), $soJuros, $this->apos(3))['juros']);
        // 5 dias = 2,5 centavos → fica embaixo.
        self::assertSame(2, $this->sut->calcular(1500, $this->vencimento(), $soJuros, $this->apos(5))['juros']);
    }

    #[Test]
    public function jurosSobemQuandoAFracaoPassaDaMetade(): void
    {
        // P=17,00 · 1 dia = 0,5666... centavo → arredonda para cima (half-down só prende o empate).
        $soJuros = new ConfigEncargos(taxaJurosMensalBp: 100);

        self::assertSame(1, $this->sut->calcular(1700, $this->vencimento(), $soJuros, $this->apos(1))['juros']);
    }

    #[Test]
    public function multaArredondaMeioParaCimaNoEmpateExato(): void
    {
        // P=0,25 a 2% = 0,005 → meio centavo exato: a multa sobe (ao contrário dos juros).
        $soMulta = new ConfigEncargos(taxaMultaBp: 200);

        self::assertSame(1, $this->sut->calcular(25, $this->vencimento(), $soMulta, $this->apos(1))['multa']);
        // P=0,24 a 2% = 0,0048 → abaixo da metade, fica embaixo.
        self::assertSame(0, $this->sut->calcular(24, $this->vencimento(), $soMulta, $this->apos(1))['multa']);
    }

    // ---------------------------------------------------------------------------------------
    // Bases configuráveis (o "democrático" do SaaS)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function multaComBaseCompostaIncideSobrePrincipalMaisJuros(): void
    {
        $config = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            taxaMultaBp: 200,
            baseMulta: BaseEncargo::Composta,
        );

        $encargos = $this->sut->calcular(17000, $this->vencimento(), $config, $this->apos(56));

        self::assertSame(317, $encargos['juros']);
        // (17000 + 317) · 2% = 346,34 → 346 (contra 340 na base principal).
        self::assertSame(346, $encargos['multa']);
    }

    #[Test]
    public function correcaoComBaseCompostaIncideSobrePrincipalMaisJurosEMulta(): void
    {
        $config = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            taxaMultaBp: 200,
            taxaCorrecaoBp: 100,
            baseCorrecao: BaseEncargo::Composta,
            taxaHonorariosBp: 1500,
            carenciaHonorariosDias: 30,
        );

        $encargos = $this->sut->calcular(17000, $this->vencimento(), $config, $this->apos(56));

        self::assertSame(317, $encargos['juros']);
        self::assertSame(340, $encargos['multa']);
        // (17000 + 317 + 340) · 1% = 176,57 → 177
        self::assertSame(177, $encargos['correcao']);
        // base dos honorários acumula a correção: (17000+317+340+177) · 15% = 2675,1 → 2675
        self::assertSame(2675, $encargos['honorarios']);
    }

    #[Test]
    public function honorariosComBasePrincipalIgnoramOsDemaisEncargos(): void
    {
        $config = new ConfigEncargos(
            taxaJurosMensalBp: 100,
            taxaMultaBp: 200,
            taxaHonorariosBp: 1500,
            baseHonorarios: BaseEncargo::Principal,
            carenciaHonorariosDias: 30,
        );

        $encargos = $this->sut->calcular(17000, $this->vencimento(), $config, $this->apos(56));

        self::assertSame(2550, $encargos['honorarios'], '17000 · 15%, sem juros nem multa na base');
    }

    // ---------------------------------------------------------------------------------------
    // Regime composto (configurável, NÃO provado contra dados reais)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function regimeCompostoCapitalizaACadaMesInteiro(): void
    {
        $config = new ConfigEncargos(taxaJurosMensalBp: 100, regimeJuros: RegimeJuros::Composto);

        // R$1.000,00 a 1% a.m. por 60 dias = 2 meses: 1000 + 1010 = 2010 centavos de juros.
        $encargos = $this->sut->calcular(100000, $this->vencimento(), $config, $this->apos(60));

        self::assertSame(2010, $encargos['juros']);
    }

    #[Test]
    public function regimeCompostoAplicaOsDiasRestantesProRata(): void
    {
        $config = new ConfigEncargos(taxaJurosMensalBp: 100, regimeJuros: RegimeJuros::Composto);

        // 45 dias = 1 mês inteiro (100000 → 101000) + 15 dias pró-rata sobre 101000 (= 505).
        $encargos = $this->sut->calcular(100000, $this->vencimento(), $config, $this->apos(45));

        self::assertSame(1505, $encargos['juros']);
    }

    #[Test]
    public function regimeCompostoRendeMaisQueOSimplesNoMesmoPrazo(): void
    {
        $simples = new ConfigEncargos(taxaJurosMensalBp: 100, regimeJuros: RegimeJuros::Simples);
        $composto = new ConfigEncargos(taxaJurosMensalBp: 100, regimeJuros: RegimeJuros::Composto);

        $jurosSimples = $this->sut->calcular(100000, $this->vencimento(), $simples, $this->apos(60))['juros'];
        $jurosCompostos = $this->sut->calcular(100000, $this->vencimento(), $composto, $this->apos(60))['juros'];

        self::assertSame(2000, $jurosSimples);
        self::assertGreaterThan($jurosSimples, $jurosCompostos);
    }

    // ---------------------------------------------------------------------------------------
    // diasDeAtraso
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function diasDeAtrasoIgnoraAHoraDoDia(): void
    {
        // Se a hora contasse, o mesmo cálculo daria valores diferentes conforme o horário do cron.
        $vencimento = new \DateTimeImmutable('2026-01-01 23:59:59');
        $referencia = new \DateTimeImmutable('2026-01-01 00:00:01');

        self::assertSame(0, $this->sut->diasDeAtraso($vencimento, $referencia));
    }

    #[Test]
    public function diasDeAtrasoContaDiasInteirosDeCalendario(): void
    {
        self::assertSame(1, $this->sut->diasDeAtraso($this->vencimento(), $this->apos(1)));
        self::assertSame(56, $this->sut->diasDeAtraso($this->vencimento(), $this->apos(56)));
        self::assertSame(2889, $this->sut->diasDeAtraso($this->vencimento(), $this->apos(2889)));
    }

    #[Test]
    public function diasDeAtrasoNuncaENegativo(): void
    {
        self::assertSame(0, $this->sut->diasDeAtraso($this->vencimento(), $this->vencimento()));
        self::assertSame(0, $this->sut->diasDeAtraso($this->apos(30), $this->vencimento()));
    }

    // ---------------------------------------------------------------------------------------
    // Conversão % ↔ R$ (o round-trip que a UI da F4 vai usar nos dois sentidos)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function valorDeTaxaConverteBasisPointsEmCentavos(): void
    {
        self::assertSame(340, CalculadoraEncargos::valorDeTaxa(17000, 200));
        self::assertSame(3400, CalculadoraEncargos::valorDeTaxa(17000, 2000));
        self::assertSame(0, CalculadoraEncargos::valorDeTaxa(17000, 0));
        self::assertSame(0, CalculadoraEncargos::valorDeTaxa(0, 2000));
        self::assertSame(0, CalculadoraEncargos::valorDeTaxa(-17000, 2000));
    }

    #[Test]
    public function taxaDeValorConverteCentavosEmBasisPoints(): void
    {
        self::assertSame(200, CalculadoraEncargos::taxaDeValor(17000, 340));
        self::assertSame(2000, CalculadoraEncargos::taxaDeValor(17000, 3400));
        self::assertSame(0, CalculadoraEncargos::taxaDeValor(0, 340), 'não existe percentual sobre base zero');
        self::assertSame(0, CalculadoraEncargos::taxaDeValor(-17000, 340));
        self::assertSame(0, CalculadoraEncargos::taxaDeValor(17000, 0));
    }

    #[Test]
    public function roundTripEntreTaxaEValorFechaNosCasosExatos(): void
    {
        foreach ([[17000, 200], [17000, 1500], [5000, 2000], [100000, 100]] as [$base, $taxaBp]) {
            $valor = CalculadoraEncargos::valorDeTaxa($base, $taxaBp);

            self::assertSame(
                $taxaBp,
                CalculadoraEncargos::taxaDeValor($base, $valor),
                sprintf('round-trip base=%d taxa=%d', $base, $taxaBp),
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /** @return array{juros:int, multa:int, correcao:int, honorarios:int} */
    private function calcular(int $principal, int $dias, int $honorariosBp): array
    {
        return $this->sut->calcular(
            $principal,
            $this->vencimento(),
            ConfigEncargos::padraoTopLife($honorariosBp),
            $this->apos($dias),
        );
    }

    /** @param array{juros:int, multa:int, correcao:int, honorarios:int} $encargos */
    private function total(int $principal, array $encargos): int
    {
        return $principal + $encargos['juros'] + $encargos['multa'] + $encargos['correcao'] + $encargos['honorarios'];
    }

    private function vencimento(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01 00:00:00');
    }

    private function apos(int $dias): \DateTimeImmutable
    {
        return $this->vencimento()->modify(sprintf('+%d days', $dias));
    }

    private function comTolerancia(int $dias): ConfigEncargos
    {
        return new ConfigEncargos(
            taxaJurosMensalBp: 100,
            taxaMultaBp: 200,
            taxaHonorariosBp: 1500,
            carenciaHonorariosDias: 30,
            toleranciaJurosMultaDias: $dias,
        );
    }
}
