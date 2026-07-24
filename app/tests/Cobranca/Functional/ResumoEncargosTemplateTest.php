<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\Enum\BaseEncargo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * A macro `resumoEncargos` (cobranca/objeto/_partials/_divida.html.twig) desenha a faixa de encargos de
 * cada obrigação. O foco deste teste é o RÓTULO da taxa de juros: ele tem de mostrar a taxa CONFIGURADA
 * ("1% a.m. pró-rata"), não o percentual que o valor pró-rata do dia representa sobre o principal — este
 * último cresce a cada dia (R$7,54 sobre R$170 = 4,44% hoje, 4,53% amanhã...) e engana quem lê o card,
 * embora o VALOR em reais esteja certo. O valor em dinheiro não é assunto deste teste (é do domínio).
 */
final class ResumoEncargosTemplateTest extends KernelTestCase
{
    private function renderizar(ObrigacaoOutput $o): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate(
            "{% import 'cobranca/objeto/_partials/_divida.html.twig' as d %}{{ d.resumoEncargos(o) }}",
        )->render(['o' => $o]);
    }

    private function obrigacao(
        int $valorOriginal,
        int $juros,
        ?int $taxaJurosEfetivaBp,
    ): ObrigacaoOutput {
        return new ObrigacaoOutput(
            id: 1,
            descricao: 'Taxa de condomínio',
            valorOriginal: $valorOriginal,
            encargosReconhecidos: $juros,
            valorAtual: $valorOriginal + $juros,
            vencimentoOriginal: new \DateTimeImmutable('2026-03-13'),
            referenciaExterna: null,
            substituidaPorAcordo: false,
            ehParcelaAcordo: false,
            parcelaDeAcordoDesfeito: false,
            juros: $juros,
            taxaJurosEfetivaBp: $taxaJurosEfetivaBp,
        );
    }

    #[Test]
    #[TestDox('O card mostra a taxa configurada (1% a.m. pró-rata), não o percentual inflado que o valor representa')]
    public function mostraATaxaConfiguradaNaoOPercentualInflado(): void
    {
        // R$170 de principal, R$7,54 de juros já acumulado (133 dias a 1% a.m. pró-rata), taxa = 100 bp.
        // O ratio antigo seria 7,54/170 = 4,44% — é ele que NÃO pode mais aparecer.
        $html = $this->renderizar($this->obrigacao(valorOriginal: 17000, juros: 754, taxaJurosEfetivaBp: 100));

        self::assertStringContainsString('1% a.m. pró-rata', $html);
        self::assertStringNotContainsString('4,44', $html);
    }

    #[Test]
    #[TestDox('Taxa não-inteira (1,5% a.m.) sai formatada em pt-BR')]
    public function taxaFracionadaSaiComVirgula(): void
    {
        $html = $this->renderizar($this->obrigacao(valorOriginal: 17000, juros: 1131, taxaJurosEfetivaBp: 150));

        self::assertStringContainsString('1,5% a.m. pró-rata', $html);
    }

    #[Test]
    #[TestDox('Dentro da tolerância (juros R$ 0,00) o rótulo da taxa ainda aparece — a taxa existe, só não incidiu')]
    public function mostraATaxaMesmoComJurosZero(): void
    {
        $html = $this->renderizar($this->obrigacao(valorOriginal: 17000, juros: 0, taxaJurosEfetivaBp: 100));

        self::assertStringContainsString('1% a.m. pró-rata', $html);
    }

    #[Test]
    #[TestDox('Sem taxa de juros configurada (null) não desenha nenhum rótulo de taxa')]
    public function semTaxaConfiguradaNaoMostraRotulo(): void
    {
        $html = $this->renderizar($this->obrigacao(valorOriginal: 17000, juros: 0, taxaJurosEfetivaBp: null));

        self::assertStringNotContainsString('a.m.', $html);
    }

    #[Test]
    #[TestDox('Config resolvida NEUTRA (0 bp) também não desenha rótulo — 0 é falsy, sem "0% a.m." enganoso')]
    public function taxaZeroDaConfigNeutraNaoMostraRotulo(): void
    {
        // Distinto do `null` acima: aqui a config FOI passada, mas é neutra (taxaJurosMensalBp = 0). O
        // `fromEntity` grava 0 (não null), e a guarda `{% if o.taxaJurosEfetivaBp %}` tem de tratar 0 como
        // falsy — senão o card mostraria "0% a.m.", que é justamente o tipo de rótulo enganoso a evitar.
        $html = $this->renderizar($this->obrigacao(valorOriginal: 17000, juros: 250, taxaJurosEfetivaBp: 0));

        self::assertStringNotContainsString('a.m.', $html);
    }
}
