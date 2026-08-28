<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os três flags de acordo do ObrigacaoOutput são VIGENTE-AWARE (ajuste 5): decidem, na UI, botão-editar
 * × cadeado × badge "Acordo desfeito". Este teste fixa o comportamento nos 4 estados possíveis.
 */
#[CoversClass(ObrigacaoOutput::class)]
final class ObrigacaoOutputTest extends TestCase
{
    private function obrigacao(): Obrigacao
    {
        return (new Obrigacao())->setDescricao('X')->setValorOriginal(10000)->setEncargosReconhecidos(500);
    }

    private function obrigacaoCom(int $valorOriginal, int $encargos = 0): Obrigacao
    {
        return (new Obrigacao())->setDescricao('X')->setValorOriginal($valorOriginal)->setEncargosReconhecidos($encargos);
    }

    #[Test]
    #[TestDox('acrescimos() fecha a identidade da linha: valorOriginal + acrescimos == valorAtual')]
    public function acrescimosFechaComOTotalDaLinha(): void
    {
        // A coluna `Acréscimos` do redesenho 1a existe para explicar a distância entre `Original` e
        // `Total` na mesma linha. Se ela não fechar, a linha passa a mostrar três números que não se
        // relacionam — e quem confere contra a contabilidade conclui que há erro onde não há.
        $out = new ObrigacaoOutput(
            id: 1,
            descricao: 'Taxa de condomínio',
            valorOriginal: 5000,
            encargosReconhecidos: 5000,
            // `valorAtual` é `Obrigacao::valorExigivel()`: original + os TRÊS encargos + honorários.
            valorAtual: 5000 + 4900 + 100 + 0 + 2000,
            vencimentoOriginal: new \DateTimeImmutable('2018-08-10'),
            referenciaExterna: null,
            substituidaPorAcordo: false,
            ehParcelaAcordo: false,
            parcelaDeAcordoDesfeito: false,
            juros: 4900,
            multa: 100,
            correcao: 0,
            honorarios: 2000,
        );

        self::assertSame(7000, $out->acrescimos(), 'juros + multa + correção + honorários');
        self::assertSame($out->valorAtual, $out->valorOriginal + $out->acrescimos());
    }

    #[Test]
    #[TestDox('acrescimos() inclui o HONORÁRIO — `encargosReconhecidos` (INV-E1) deixa ele de fora')]
    public function acrescimosNaoEhOEncargosReconhecidos(): void
    {
        // Armadilha real: `encargosReconhecidos` é a soma dos TRÊS encargos, sem honorário. Desde a spec
        // `cobranca-honorario-no-total.md` o honorário está DENTRO do exigível, então usá-lo na coluna
        // `Acréscimos` faria `Original + Acréscimos` ficar MENOR que o `Total` ao lado, em toda carteira
        // que cobra honorário — que são todas as três de produção.
        $out = new ObrigacaoOutput(
            id: 1,
            descricao: 'X',
            valorOriginal: 10000,
            encargosReconhecidos: 1000,
            valorAtual: 10000 + 1000 + 2000,
            vencimentoOriginal: new \DateTimeImmutable('2026-01-10'),
            referenciaExterna: null,
            substituidaPorAcordo: false,
            ehParcelaAcordo: false,
            parcelaDeAcordoDesfeito: false,
            juros: 1000,
            honorarios: 2000,
        );

        self::assertSame(3000, $out->acrescimos());
        self::assertNotSame($out->encargosReconhecidos, $out->acrescimos(), 'os dois NÃO são a mesma coisa');
        self::assertSame($out->valorAtual, $out->valorOriginal + $out->acrescimos());
    }

    #[Test]
    #[TestDox('Sem nenhum encargo, acrescimos() é zero e Original == Total')]
    public function semEncargosAcrescimosEZero(): void
    {
        $out = ObrigacaoOutput::fromEntity($this->obrigacaoCom(10000));

        self::assertSame(0, $out->acrescimos());
        self::assertSame($out->valorAtual, $out->valorOriginal);
    }

    #[Test]
    public function obrigacaoComumNaoTemNenhumFlagDeAcordo(): void
    {
        $out = ObrigacaoOutput::fromEntity($this->obrigacao());

        self::assertFalse($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
        self::assertSame(10500, $out->valorAtual, 'valorAtual = original + encargos');
    }

    #[Test]
    public function substituidaPorAcordoVigente(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Ativo)),
        );

        self::assertTrue($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
    }

    #[Test]
    public function parcelaDeAcordoVigente(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Cumprido)),
        );

        self::assertTrue($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
        self::assertFalse($out->substituidaPorAcordo);
    }

    #[Test]
    public function parcelaDeAcordoRompidoOuCanceladoViraHistorico(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Cancelado)),
        );

        self::assertTrue($out->parcelaDeAcordoDesfeito);
        self::assertFalse($out->ehParcelaAcordo, 'parcela de acordo desfeito não é mais "parcela vigente"');
        self::assertFalse($out->substituidaPorAcordo);
    }

    #[Test]
    public function originalSubstituidaPorAcordoCanceladoVoltaAoNormal(): void
    {
        // O acordo que a substituía foi cancelado: nenhum flag de trava → volta a ser editável.
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Rompido)),
        );

        self::assertFalse($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
    }

    #[Test]
    public function restanteDescontaOAlocadoDoValorAtual(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 120000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 40000);

        self::assertSame(40000, $output->alocado);
        self::assertSame(80000, $output->restante());
        self::assertFalse($output->quitada());
    }

    #[Test]
    public function restanteTemPisoZeroQuandoSuperAlocada(): void
    {
        // Alocação manual não tem teto por obrigação (beco conhecido, spec §10):
        // o DTO não pode devolver negativo para a tela.
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 130000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function quitadaQuandoAlocadoCobreExatamenteOValor(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 20000);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 120000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function alocadoDefaultZeroPreservaOComportamentoAntigo(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao);

        self::assertSame(0, $output->alocado);
        self::assertSame(100000, $output->restante());
    }

    #[Test]
    public function fromEntityCopiaOsQuatroOverridesCrusDeTaxa(): void
    {
        // FIX crítico (Task 9): sem estes 4 campos crus (bp), o modal de Editar não consegue reidratar o
        // override ATUAL da obrigação — reabrir sempre nasceria "herda" e qualquer submissão apagaria a
        // taxa própria em silêncio.
        $obrigacao = $this->obrigacao()
            ->setTaxaJurosMensalBp(150)
            ->setTaxaMultaBp(200)
            ->setTaxaCorrecaoBp(50)
            ->setTaxaHonorariosBp(1000);

        $output = ObrigacaoOutput::fromEntity($obrigacao);

        self::assertSame(150, $output->taxaJurosMensalBp);
        self::assertSame(200, $output->taxaMultaBp);
        self::assertSame(50, $output->taxaCorrecaoBp);
        self::assertSame(1000, $output->taxaHonorariosBp);
    }

    #[Test]
    public function fromEntitySemOverrideDeixaOsQuatroCamposNulos(): void
    {
        // Obrigação que herda tudo do caso (nunca teve override próprio) — os 4 campos crus têm de sair
        // `null`, não 0: 0bp seria uma taxa própria de 0%, algo bem diferente de "herda".
        $output = ObrigacaoOutput::fromEntity($this->obrigacao());

        self::assertNull($output->taxaJurosMensalBp);
        self::assertNull($output->taxaMultaBp);
        self::assertNull($output->taxaCorrecaoBp);
        self::assertNull($output->taxaHonorariosBp);
    }

    #[Test]
    public function fromEntityExpoeATaxaDeJurosEFETIVAResolvidaDaCascata(): void
    {
        // Diferente dos 4 overrides CRUS acima: `taxaJurosEfetivaBp` é a taxa que a cascata
        // Carteira→Caso→Obrigação REALMENTE aplica (vem do ConfigEncargos já resolvido). É o que o
        // card precisa para rotular "1% a.m. pró-rata" em vez do percentual inflado que cresce com os dias.
        // Aqui a obrigação NÃO tem override próprio (herda), mas a config resolvida diz 1% a.m. (100 bp).
        $config = new ConfigEncargos(taxaJurosMensalBp: 100);

        $output = ObrigacaoOutput::fromEntity($this->obrigacao(), config: $config);

        self::assertSame(100, $output->taxaJurosEfetivaBp);
    }

    #[Test]
    public function fromEntitySemConfigDeixaATaxaDeJurosEfetivaNula(): void
    {
        // Chamadores antigos não passam config — sem taxa resolvida, o campo sai `null` (não 0), e o
        // template não exibe nenhum rótulo de taxa (melhor nada do que um "0% a.m." enganoso).
        $output = ObrigacaoOutput::fromEntity($this->obrigacao());

        self::assertNull($output->taxaJurosEfetivaBp);
    }
}
