<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Obrigacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A invariante que segura a migração inteira (INV-E1, decisão D1): separar os encargos em juros,
 * multa e correção NÃO pode mexer em um único centavo de saldo já existente.
 *
 * `getEncargosReconhecidos()` virou DERIVADO (`juros + multa + correcao`) e o setter antigo virou
 * ponte de compatibilidade. É por isso que `CalculadoraSaldo`, FIFO, Acordo, Dashboard e a suíte de
 * saldo continuaram intactos: a igualdade "soma dos três == agregado de antes" vale por
 * CONSTRUÇÃO, não por alguém lembrar de mantê-la.
 *
 * A segunda invariante aqui é a INV-E2: honorário NÃO é dívida do credor e fica FORA do exigível.
 * Se ele vazar para `valorExigivel()`, contamina o saldo que alimenta acordos e pagamentos.
 */
#[CoversClass(Obrigacao::class)]
final class ObrigacaoEncargosTest extends TestCase
{
    #[Test]
    public function pontesDeCompatibilidadePreservamOSaldoAoCentavo(): void
    {
        $obrigacao = $this->obrigacao(10000)->setEncargosReconhecidos(500);

        self::assertSame(500, $obrigacao->getEncargosReconhecidos());
        self::assertSame(10500, $obrigacao->valorExigivel());
    }

    #[Test]
    public function pontePorReconhecerEncargosSeComportaIgualAoSetter(): void
    {
        $obrigacao = $this->obrigacao(10000)->reconhecerEncargos(500);

        self::assertSame(500, $obrigacao->getEncargosReconhecidos());
        self::assertSame(10500, $obrigacao->valorExigivel());
    }

    #[Test]
    public function aPonteJogaOAgregadoEmJurosEZeraOsDemais(): void
    {
        // Preserva o saldo, mas perde o split — é o custo assumido do fallback da migração
        // (spec §10.2). O split verdadeiro vem da reimportação das planilhas.
        $obrigacao = $this->obrigacao(10000)->setEncargosReconhecidos(500);

        self::assertSame(500, $obrigacao->getJuros());
        self::assertSame(0, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
    }

    #[Test]
    public function aPonteSobrescreveUmSplitAnteriorEmVezDeSomarNele(): void
    {
        $obrigacao = $this->obrigacao(10000)
            ->definirEncargos(100, 200, 50, 900, $this->agora())
            ->setEncargosReconhecidos(500);

        self::assertSame(500, $obrigacao->getJuros());
        self::assertSame(0, $obrigacao->getMulta());
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(10500, $obrigacao->valorExigivel());
    }

    #[Test]
    public function obrigacaoNovaNaoTemEncargoNenhum(): void
    {
        $obrigacao = $this->obrigacao(10000);

        self::assertSame(0, $obrigacao->getEncargosReconhecidos());
        self::assertSame(10000, $obrigacao->valorExigivel());
        self::assertSame(10000, $obrigacao->totalComHonorarios());
    }

    #[Test]
    public function encargosSeparadosSomamNoAgregadoENoExigivel(): void
    {
        $obrigacao = $this->obrigacao(10000)->definirEncargos(100, 200, 50, 900, $this->agora());

        self::assertSame(350, $obrigacao->getEncargosReconhecidos(), 'juros + multa + correção');
        self::assertSame(10350, $obrigacao->valorExigivel());
    }

    #[Test]
    public function honorariosFicamForaDoExigivelEEntramSoNoTotal(): void
    {
        $obrigacao = $this->obrigacao(10000)->definirEncargos(100, 200, 50, 900, $this->agora());

        self::assertSame(10350, $obrigacao->valorExigivel(), 'honorário não é dívida do credor (INV-E2)');
        self::assertSame(11250, $obrigacao->totalComHonorarios());
        self::assertSame(900, $obrigacao->getHonorarios());
    }

    #[Test]
    public function definirEncargosGuardaOsQuatroValoresEADataDeReferencia(): void
    {
        $referencia = new \DateTimeImmutable('2026-07-19 03:00:00');

        $obrigacao = $this->obrigacao(10000)->definirEncargos(100, 200, 50, 900, $referencia);

        self::assertSame(100, $obrigacao->getJuros());
        self::assertSame(200, $obrigacao->getMulta());
        self::assertSame(50, $obrigacao->getCorrecao());
        self::assertSame(900, $obrigacao->getHonorarios());
        self::assertSame($referencia, $obrigacao->getEncargosAtualizadosEm());
    }

    #[Test]
    public function definirEncargosNaoCongelaSozinho(): void
    {
        // Materializar pelo cron é rotina; congelar é decisão humana (edição/importação).
        $obrigacao = $this->obrigacao(10000)->definirEncargos(100, 200, 50, 900, $this->agora());

        self::assertFalse($obrigacao->encargosCongelados());
        self::assertNull($obrigacao->getEncargosCongeladosEm());
    }

    #[Test]
    public function congelarMarcaAObrigacaoComoIntocavelPeloCron(): void
    {
        $em = new \DateTimeImmutable('2026-07-19 03:00:00');

        $obrigacao = $this->obrigacao(10000)->congelarEncargos($em);

        self::assertTrue($obrigacao->encargosCongelados());
        self::assertSame($em, $obrigacao->getEncargosCongeladosEm());
    }

    #[Test]
    public function descongelarDevolveAObrigacaoAoRecalculoAutomatico(): void
    {
        $obrigacao = $this->obrigacao(10000)
            ->congelarEncargos($this->agora())
            ->descongelarEncargos();

        self::assertFalse($obrigacao->encargosCongelados());
        self::assertNull($obrigacao->getEncargosCongeladosEm());
    }

    #[Test]
    public function congelarNaoAlteraOsValoresDeEncargo(): void
    {
        $obrigacao = $this->obrigacao(10000)
            ->definirEncargos(100, 200, 50, 900, $this->agora())
            ->congelarEncargos($this->agora());

        self::assertSame(350, $obrigacao->getEncargosReconhecidos());
        self::assertSame(10350, $obrigacao->valorExigivel());
        self::assertSame(11250, $obrigacao->totalComHonorarios());
    }

    #[Test]
    public function osSettersIndividuaisTambemAlimentamOAgregado(): void
    {
        $obrigacao = $this->obrigacao(10000)
            ->setJuros(100)
            ->setMulta(200)
            ->setCorrecao(50)
            ->setHonorarios(900);

        self::assertSame(350, $obrigacao->getEncargosReconhecidos());
        self::assertSame(10350, $obrigacao->valorExigivel());
    }

    #[Test]
    public function overridesDeConfiguracaoNascemNulosSignificandoHerdaOCaso(): void
    {
        $obrigacao = $this->obrigacao(10000);

        self::assertNull($obrigacao->getTaxaJurosMensalBp());
        self::assertNull($obrigacao->getRegimeJuros());
        self::assertNull($obrigacao->getTaxaMultaBp());
        self::assertNull($obrigacao->getBaseMulta());
        self::assertNull($obrigacao->getTaxaCorrecaoBp());
        self::assertNull($obrigacao->getBaseCorrecao());
        self::assertNull($obrigacao->getBaseHonorarios());
        self::assertNull($obrigacao->getCarenciaHonorariosDias());
        self::assertNull($obrigacao->getToleranciaJurosMultaDias());
    }

    private function obrigacao(int $valorOriginal): Obrigacao
    {
        return (new Obrigacao())->setDescricao('Mensalidade')->setValorOriginal($valorOriginal);
    }

    private function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-19 03:00:00');
    }
}
