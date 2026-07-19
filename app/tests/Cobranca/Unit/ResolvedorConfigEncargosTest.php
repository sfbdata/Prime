<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A cascata Carteira → Objeto/Caso → Obrigação é resolvida CAMPO A CAMPO. A armadilha que estes
 * testes fecham é a resolução "bloco a bloco": se sobrepor a taxa de juros na obrigação apagasse a
 * multa e os honorários herdados, o escritório perderia dinheiro sem nada aparecer na tela.
 */
#[CoversClass(ResolvedorConfigEncargos::class)]
final class ResolvedorConfigEncargosTest extends TestCase
{
    private ResolvedorConfigEncargos $sut;

    protected function setUp(): void
    {
        $this->sut = new ResolvedorConfigEncargos();
    }

    // ---------------------------------------------------------------------------------------
    // Nível 1 — Carteira (fundo da cascata)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function carteiraEntregaAConfiguracaoCompleta(): void
    {
        $config = $this->sut->resolverDaCarteira($this->carteiraTopLife());

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(RegimeJuros::Simples, $config->regimeJuros);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(BaseEncargo::Principal, $config->baseMulta);
        self::assertSame(0, $config->taxaCorrecaoBp);
        self::assertSame(BaseEncargo::Principal, $config->baseCorrecao);
        self::assertSame(2000, $config->taxaHonorariosBp);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(0, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function carteiraRecemMigradaEntregaConfiguracaoNeutra(): void
    {
        // Decisão D4: carteira sem nada configurado não gera encargo nenhum.
        $config = $this->sut->resolverDaCarteira(new Carteira());

        self::assertSame(0, $config->taxaJurosMensalBp);
        self::assertSame(0, $config->taxaMultaBp);
        self::assertSame(0, $config->taxaCorrecaoBp);
        self::assertSame(0, $config->taxaHonorariosBp);
        self::assertSame(0, $config->carenciaHonorariosDias);
        self::assertSame(0, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function carenciaDeHonorariosNulaCaiParaAToleranciaDeAtrasoDaCarteira(): void
    {
        // Decisão D3: a tolerância de ~30 dias já configurada nas carteiras é, na prática, carência
        // de HONORÁRIOS — juros e multa valem desde o 1º dia.
        $carteira = $this->carteiraTopLife()
            ->setCarenciaHonorariosDias(null)
            ->setToleranciaAtrasoDias(45);

        self::assertSame(45, $this->sut->resolverDaCarteira($carteira)->carenciaHonorariosDias);
    }

    #[Test]
    public function carenciaExplicitaGanhaDaToleranciaDeAtraso(): void
    {
        $carteira = $this->carteiraTopLife()
            ->setCarenciaHonorariosDias(15)
            ->setToleranciaAtrasoDias(45);

        self::assertSame(15, $this->sut->resolverDaCarteira($carteira)->carenciaHonorariosDias);
    }

    // ---------------------------------------------------------------------------------------
    // Taxa de honorários — derivada do que JÁ EXISTIA (decisão D2), sem coluna nova
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function taxaDeHonorariosVemDoPercentualDecimalConvertidoParaBasisPoints(): void
    {
        $carteira = $this->carteiraTopLife()->setPercentualHonorarios('15.50');

        self::assertSame(1550, $this->sut->resolverDaCarteira($carteira)->taxaHonorariosBp);
    }

    #[Test]
    public function formaSemPercentualZeraAHonorario(): void
    {
        $carteira = $this->carteiraTopLife()
            ->setFormaHonorarios(FormaHonorarios::SemPercentual)
            ->setPercentualHonorarios('20.00');

        self::assertSame(
            0,
            $this->sut->resolverDaCarteira($carteira)->taxaHonorariosBp,
            'a forma manda: sem percentual não há honorário, mesmo com o campo preenchido',
        );
    }

    #[Test]
    public function percentualNuloZeraAHonorario(): void
    {
        $carteira = $this->carteiraTopLife()->setPercentualHonorarios(null);

        self::assertSame(0, $this->sut->resolverDaCarteira($carteira)->taxaHonorariosBp);
    }

    // ---------------------------------------------------------------------------------------
    // Nível 2 — Caso
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function casoSemOverrideHerdaTudoDaCarteira(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife());

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function overrideParcialNoCasoNaoApagaOsDemaisCamposHerdados(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife())->setTaxaJurosMensalBp(300);

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(300, $config->taxaJurosMensalBp, 'o override vale');
        self::assertSame(200, $config->taxaMultaBp, 'a multa continua herdada da carteira');
        self::assertSame(BaseEncargo::Principal, $config->baseMulta);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(2000, $config->taxaHonorariosBp);
    }

    #[Test]
    public function casoUsaOProprioSnapshotDeHonorariosENaoOPercentualAtualDaCarteira(): void
    {
        // SPEC §18.2/§18.3: mudar o padrão da carteira não pode recalcular casos antigos.
        $caso = $this->casoDe($this->carteiraTopLife())
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('15.00');

        self::assertSame(1500, $this->sut->resolverDoCaso($caso)->taxaHonorariosBp);
    }

    #[Test]
    public function casoPodeSobreporRegimeBasesECarencia(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife())
            ->setRegimeJuros(RegimeJuros::Composto)
            ->setBaseMulta(BaseEncargo::Composta)
            ->setBaseCorrecao(BaseEncargo::Composta)
            ->setBaseHonorarios(BaseEncargo::Principal)
            ->setCarenciaHonorariosDias(0)
            ->setToleranciaJurosMultaDias(7);

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(RegimeJuros::Composto, $config->regimeJuros);
        self::assertSame(BaseEncargo::Composta, $config->baseMulta);
        self::assertSame(BaseEncargo::Composta, $config->baseCorrecao);
        self::assertSame(BaseEncargo::Principal, $config->baseHonorarios);
        self::assertSame(0, $config->carenciaHonorariosDias);
        self::assertSame(7, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function overrideComZeroNoCasoDesligaOEncargoEmVezDeHerdar(): void
    {
        // Zero é um override LEGÍTIMO (só null significa "herda"): é assim que o gestor isenta um
        // objeto específico de juros sem mexer na carteira inteira.
        $caso = $this->casoDe($this->carteiraTopLife())->setTaxaJurosMensalBp(0);

        self::assertSame(0, $this->sut->resolverDoCaso($caso)->taxaJurosMensalBp);
    }

    // ---------------------------------------------------------------------------------------
    // Nível 3 — Obrigação
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function obrigacaoSemOverrideHerdaACascataInteira(): void
    {
        $obrigacao = $this->obrigacaoDe($this->casoDe($this->carteiraTopLife()));

        $config = $this->sut->resolver($obrigacao);

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(2000, $config->taxaHonorariosBp);
        self::assertSame(30, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function overrideParcialNaObrigacaoNaoApagaOsDemaisCamposHerdados(): void
    {
        $obrigacao = $this->obrigacaoDe($this->casoDe($this->carteiraTopLife()))
            ->setTaxaJurosMensalBp(500);

        $config = $this->sut->resolver($obrigacao);

        self::assertSame(500, $config->taxaJurosMensalBp);
        self::assertSame(200, $config->taxaMultaBp, 'a multa segue vindo da carteira');
        self::assertSame(2000, $config->taxaHonorariosBp, 'a alíquota segue vindo do snapshot do caso');
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(0, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function obrigacaoGanhaDoCasoQueGanhaDaCarteiraNoMesmoCampo(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife())->setTaxaMultaBp(500);
        $obrigacao = $this->obrigacaoDe($caso)->setTaxaMultaBp(900);

        self::assertSame(200, $this->sut->resolverDaCarteira($this->carteiraTopLife())->taxaMultaBp);
        self::assertSame(500, $this->sut->resolverDoCaso($caso)->taxaMultaBp);
        self::assertSame(900, $this->sut->resolver($obrigacao)->taxaMultaBp);
    }

    #[Test]
    public function obrigacaoHerdaOOverrideDoCasoQuandoNaoTemOSeu(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife())->setCarenciaHonorariosDias(60);
        $obrigacao = $this->obrigacaoDe($caso);

        self::assertSame(60, $this->sut->resolver($obrigacao)->carenciaHonorariosDias);
    }

    // ---------------------------------------------------------------------------------------
    // Navegação nula — degradar, nunca explodir (é cálculo de dinheiro em tela)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function obrigacaoSemCasoDegradaParaConfiguracaoNeutra(): void
    {
        $config = $this->sut->resolver(new Obrigacao());

        self::assertSame(0, $config->taxaJurosMensalBp);
        self::assertSame(0, $config->taxaMultaBp);
        self::assertSame(0, $config->taxaHonorariosBp);
        self::assertSame(0, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function casoSemObjetoDegradaParaConfiguracaoNeutra(): void
    {
        $config = $this->sut->resolverDoCaso(new CasoCobranca());

        self::assertSame(0, $config->taxaJurosMensalBp);
        self::assertSame(0, $config->taxaMultaBp);
        self::assertSame(0, $config->taxaHonorariosBp);
        self::assertSame(0, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function objetoSemCarteiraDegradaParaConfiguracaoNeutra(): void
    {
        $caso = (new CasoCobranca())->setObjeto(new ObjetoCobranca());

        self::assertSame(0, $this->sut->resolverDoCaso($caso)->taxaJurosMensalBp);
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /** Carteira com o preset comprovado da operação real: 1% a.m. + 2% de multa + 20% de honorários. */
    private function carteiraTopLife(): Carteira
    {
        return (new Carteira())
            ->setTaxaJurosMensalBp(100)
            ->setRegimeJuros(RegimeJuros::Simples)
            ->setTaxaMultaBp(200)
            ->setBaseMulta(BaseEncargo::Principal)
            ->setTaxaCorrecaoBp(0)
            ->setBaseCorrecao(BaseEncargo::Principal)
            ->setBaseHonorarios(BaseEncargo::Composta)
            ->setCarenciaHonorariosDias(30)
            ->setToleranciaJurosMultaDias(0)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');
    }

    /** Caso ligado à carteira pelo objeto, com o snapshot de honorários espelhando a carteira. */
    private function casoDe(Carteira $carteira): CasoCobranca
    {
        return (new CasoCobranca())
            ->setObjeto((new ObjetoCobranca())->setCarteira($carteira))
            ->setFormaHonorarios($carteira->getFormaHonorarios())
            ->setPercentualHonorarios($carteira->getPercentualHonorarios());
    }

    private function obrigacaoDe(CasoCobranca $caso): Obrigacao
    {
        return (new Obrigacao())->setCaso($caso);
    }
}
