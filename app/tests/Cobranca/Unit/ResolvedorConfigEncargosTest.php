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
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A cascata Carteira → Objeto → Obrigação é resolvida CAMPO A CAMPO. A armadilha que estes testes
 * fecham é a resolução "bloco a bloco": se sobrepor a taxa de juros na obrigação apagasse a multa e
 * os honorários herdados, o escritório perderia dinheiro sem nada aparecer na tela.
 *
 * #9-T1: o NÍVEL 2 (o "meio") da cascata é o OBJETO — o `CasoCobranca` deixou de participar da
 * resolução (`resolverDoCaso` delega integralmente a `resolverDoObjeto`). As colunas de config do
 * Caso continuam existindo (coluna-sombra), mas nenhum teste aqui monta override nelas esperando
 * que o resultado mude — é exatamente o contrário que se prova (`casoIgnoraSuaPropriaConfigMorta`).
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
    // Taxa de honorários — derivada do que JÁ EXISTIA (decisão D2), sem coluna nova NA CARTEIRA
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
    // Nível 2 — Objeto (#9-T1: o "meio" da cascata)
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function objetoSemOverrideHerdaTudoDaCarteira(): void
    {
        $objeto = (new ObjetoCobranca())->setCarteira($this->carteiraTopLife());

        $config = $this->sut->resolverDoObjeto($objeto);

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(2000, $config->taxaHonorariosBp, 'honorários herdam ao vivo da carteira (T1)');
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function overrideParcialNoObjetoNaoApagaOsDemaisCamposHerdados(): void
    {
        $objeto = (new ObjetoCobranca())
            ->setCarteira($this->carteiraTopLife())
            ->setTaxaJurosMensalBp(300);

        $config = $this->sut->resolverDoObjeto($objeto);

        self::assertSame(300, $config->taxaJurosMensalBp, 'o override vale');
        self::assertSame(200, $config->taxaMultaBp, 'a multa continua herdada da carteira');
        self::assertSame(BaseEncargo::Principal, $config->baseMulta);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(2000, $config->taxaHonorariosBp);
    }

    #[Test]
    public function objetoPodeSobreporRegimeBasesCarenciaETolerancia(): void
    {
        $objeto = (new ObjetoCobranca())
            ->setCarteira($this->carteiraTopLife())
            ->setRegimeJuros(RegimeJuros::Composto)
            ->setBaseMulta(BaseEncargo::Composta)
            ->setBaseCorrecao(BaseEncargo::Composta)
            ->setBaseHonorarios(BaseEncargo::Principal)
            ->setCarenciaHonorariosDias(0)
            ->setToleranciaJurosMultaDias(7);

        $config = $this->sut->resolverDoObjeto($objeto);

        self::assertSame(RegimeJuros::Composto, $config->regimeJuros);
        self::assertSame(BaseEncargo::Composta, $config->baseMulta);
        self::assertSame(BaseEncargo::Composta, $config->baseCorrecao);
        self::assertSame(BaseEncargo::Principal, $config->baseHonorarios);
        self::assertSame(0, $config->carenciaHonorariosDias);
        self::assertSame(7, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function overrideComZeroNoObjetoDesligaOEncargoEmVezDeHerdar(): void
    {
        // Zero é um override LEGÍTIMO (só null significa "herda"): é assim que o gestor isenta um
        // objeto específico de juros sem mexer na carteira inteira.
        $objeto = (new ObjetoCobranca())
            ->setCarteira($this->carteiraTopLife())
            ->setTaxaJurosMensalBp(0);

        self::assertSame(0, $this->sut->resolverDoObjeto($objeto)->taxaJurosMensalBp);
    }

    #[Test]
    #[TestDox('T1: honorários cascateiam AO VIVO como qualquer outro campo — override do objeto vence a carteira')]
    public function overrideDeHonorariosNoObjetoVenceSobreACarteira(): void
    {
        $objeto = (new ObjetoCobranca())
            ->setCarteira($this->carteiraTopLife()) // 20% na carteira
            ->setTaxaHonorariosBp(1500); // 15% no objeto

        self::assertSame(1500, $this->sut->resolverDoObjeto($objeto)->taxaHonorariosBp);
    }

    #[Test]
    #[TestDox('T1: mudar a carteira reflete no objeto sem override — nada precisa ser tocado no objeto/caso')]
    public function mudarACarteiraRefleteNoObjetoSemOverrideDeHonorarios(): void
    {
        $carteira = $this->carteiraTopLife()->setPercentualHonorarios('10.00');
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        self::assertSame(1000, $this->sut->resolverDoObjeto($objeto)->taxaHonorariosBp);

        // A carteira muda de padrão (ex.: reconfiguração do escritório) — o objeto (sem override)
        // reflete IMEDIATAMENTE, sem UPDATE nenhum nele: é a correção dos 194 casos legados (spec §2).
        $carteira->setPercentualHonorarios('20.00');

        self::assertSame(2000, $this->sut->resolverDoObjeto($objeto)->taxaHonorariosBp);
    }

    #[Test]
    public function objetoSemCarteiraDegradaParaConfiguracaoNeutra(): void
    {
        self::assertSame(0, $this->sut->resolverDoObjeto(new ObjetoCobranca())->taxaJurosMensalBp);
    }

    // ---------------------------------------------------------------------------------------
    // Nível 2 — Caso: DELEGA ao objeto (T1). A config própria do caso virou coluna-sombra morta.
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function casoDelegaAoObjetoSemOverride(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife());

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(2000, $config->taxaHonorariosBp);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
    }

    #[Test]
    public function casoRefleteOOverrideDoObjeto(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife());
        $caso->getObjeto()->setTaxaMultaBp(500)->setTaxaHonorariosBp(1234);

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(500, $config->taxaMultaBp);
        self::assertSame(1234, $config->taxaHonorariosBp);
        self::assertSame(100, $config->taxaJurosMensalBp, 'juros segue herdado da carteira (sem override)');
    }

    #[Test]
    #[TestDox('T1: config PRÓPRIA do caso (mesmo preenchida) é IGNORADA — a fonte é o objeto/carteira')]
    public function casoIgnoraSuaPropriaConfigMortaMesmoPreenchidaComValoresDiferentes(): void
    {
        // O caso tem colunas preenchidas com valores DIFERENTES da carteira — se o resolvedor ainda
        // lesse o caso, o resultado seria 999/900/AcrescidoDivida-99%. A prova de que a delegação é
        // TOTAL: o resultado tem de ser exatamente o da carteira (via objeto sem override).
        $carteira = $this->carteiraTopLife();
        $caso = $this->casoDe($carteira);
        $caso
            ->setTaxaJurosMensalBp(999)
            ->setTaxaMultaBp(900)
            ->setRegimeJuros(RegimeJuros::Composto)
            ->setBaseMulta(BaseEncargo::Composta)
            ->setBaseCorrecao(BaseEncargo::Composta)
            ->setBaseHonorarios(BaseEncargo::Principal)
            ->setCarenciaHonorariosDias(0)
            ->setToleranciaJurosMultaDias(99)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('99.00');

        $config = $this->sut->resolverDoCaso($caso);

        self::assertSame(100, $config->taxaJurosMensalBp, 'a config PRÓPRIA do caso é morta — vem da carteira');
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(RegimeJuros::Simples, $config->regimeJuros);
        self::assertSame(BaseEncargo::Principal, $config->baseMulta);
        self::assertSame(BaseEncargo::Principal, $config->baseCorrecao);
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(0, $config->toleranciaJurosMultaDias);
        self::assertSame(2000, $config->taxaHonorariosBp, 'honorários vêm da carteira (20%), não do snapshot 99% do caso');
    }

    #[Test]
    #[TestDox('T1: mudar a carteira reflete em caso ANTIGO (sem snapshot), mesmo sem tocar o caso/objeto')]
    public function mudarACarteiraRefleteEmCasoAntigoSemSnapshot(): void
    {
        // "Caso antigo": objeto e caso SEM nenhum override — igual aos 194 casos legados da spec §2.
        $carteira = $this->carteiraTopLife()->setPercentualHonorarios('10.00');
        $caso = (new CasoCobranca())->setObjeto((new ObjetoCobranca())->setCarteira($carteira));

        self::assertSame(1000, $this->sut->resolverDoCaso($caso)->taxaHonorariosBp);

        // Reconfigura a carteira (20%) — SEM tocar caso nem objeto.
        $carteira->setPercentualHonorarios('20.00');

        self::assertSame(2000, $this->sut->resolverDoCaso($caso)->taxaHonorariosBp, 'reflete ao vivo, sem UPDATE no caso');
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
        self::assertSame(2000, $config->taxaHonorariosBp, 'a alíquota segue vindo da carteira (via objeto, T1)');
        self::assertSame(BaseEncargo::Composta, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(0, $config->toleranciaJurosMultaDias);
    }

    #[Test]
    public function obrigacaoGanhaDoObjetoQueGanhaDaCarteiraNoMesmoCampo(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife());
        $caso->getObjeto()->setTaxaMultaBp(500);
        $obrigacao = $this->obrigacaoDe($caso)->setTaxaMultaBp(900);

        self::assertSame(200, $this->sut->resolverDaCarteira($this->carteiraTopLife())->taxaMultaBp);
        self::assertSame(500, $this->sut->resolverDoCaso($caso)->taxaMultaBp);
        self::assertSame(900, $this->sut->resolver($obrigacao)->taxaMultaBp);
    }

    #[Test]
    public function obrigacaoHerdaOOverrideDoObjetoQuandoNaoTemOSeu(): void
    {
        $caso = $this->casoDe($this->carteiraTopLife());
        $caso->getObjeto()->setCarenciaHonorariosDias(60);
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

    /** Caso ligado à carteira pelo objeto — sem nenhum override (nem no objeto, nem no caso). */
    private function casoDe(Carteira $carteira): CasoCobranca
    {
        return (new CasoCobranca())->setObjeto((new ObjetoCobranca())->setCarteira($carteira));
    }

    private function obrigacaoDe(CasoCobranca $caso): Obrigacao
    {
        return (new Obrigacao())->setCaso($caso);
    }
}
