<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Form\AcordoCriarType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O que o form de acordo OFERECE ao gestor (ajuste 10, spec §5.3 / INV-U9). O bug de dinheiro: as duas
 * fábricas de opção usavam o valor exigível CHEIO, então uma obrigação já parcialmente paga chegava ao
 * gerador de parcelas pelo valor original — o gestor renegociava o que o devedor JÁ PAGOU e o pagamento
 * evaporava do saldo (a substituída sai do exigível levando a alocação junto, `CalculadoraSaldo:57`).
 *
 * O conserto é de SUGESTÃO e INFORMAÇÃO, não de cálculo: o valor oferecido passa a ser o REMANESCENTE
 * (`valorExigivel − alocado`) e o rótulo diz quanto já entrou. `CalculadoraSaldo` não muda (D7).
 */
#[CoversClass(AcordoCriarType::class)]
final class AcordoCriarTypeTest extends TestCase
{
    private function obrigacaoCom(int $id, int $valorOriginal, int $encargos = 0, string $descricao = 'Abril/2026'): Obrigacao
    {
        $obrigacao = (new Obrigacao())
            ->setDescricao($descricao)
            ->setValorOriginal($valorOriginal)
            ->setEncargosReconhecidos($encargos)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-04-10'));

        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, $id);

        return $obrigacao;
    }

    #[Test]
    public function valoresObrigacoesSugereORemanescenteENaoOValorCheio(): void
    {
        // O bug: sugeria 120000 numa obrigação com 40000 já pagos, fazendo o gestor renegociar
        // R$400 que o devedor JÁ PAGOU (spec §5.3).
        $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 0);

        $valores = AcordoCriarType::valoresObrigacoes([$obrigacao], [7 => 40000]);

        self::assertSame([7 => 80000], $valores);
    }

    #[Test]
    public function valoresObrigacoesSemAlocacaoSegueNoValorExigivel(): void
    {
        $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 20000);

        self::assertSame([7 => 140000], AcordoCriarType::valoresObrigacoes([$obrigacao], []));
    }

    #[Test]
    public function valoresObrigacoesTemPisoZeroQuandoSuperAlocada(): void
    {
        // Alocação manual não tem teto por obrigação (beco conhecido, spec §10): o gerador não pode
        // receber valor negativo — espelha o piso de `ObrigacaoOutput::restante()` (T1).
        $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 100000, encargos: 0);

        self::assertSame([7 => 0], AcordoCriarType::valoresObrigacoes([$obrigacao], [7 => 130000]));
    }

    #[Test]
    public function opcoesObrigacoesSinalizaOQueJaFoiRecebido(): void
    {
        $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 0, descricao: 'Abril/2026');

        $opcoes = AcordoCriarType::opcoesObrigacoes([$obrigacao], [7 => 40000]);

        $label = array_key_first($opcoes);
        self::assertStringContainsString('800,00', $label, 'mostra o remanescente');
        self::assertStringContainsString('400,00 já recebidos', $label, 'avisa que houve pagamento');
    }

    #[Test]
    public function opcoesObrigacoesSemAlocacaoNaoFalaEmRecebido(): void
    {
        // O default `[]` preserva `MontadorModaisCaso::financeiros()`, que monta o select do modal de
        // PAGAMENTO com a mesma fábrica e não passa mapa: sem alocação, nada muda no rótulo.
        $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 20000, descricao: 'Abril/2026');

        $opcoes = AcordoCriarType::opcoesObrigacoes([$obrigacao]);

        self::assertSame(['Abril/2026 — venc 10/04/2026 — R$ 1.400,00' => 7], $opcoes);
    }

    #[Test]
    public function opcoesObrigacoesIdenticasNaoSeApagam(): void
    {
        // Mesma descrição, mesmo vencimento, mesmo valor — uma reimportação basta para produzir isso. A
        // chave do mapa é o rótulo: sem desempate, a segunda sobrescrevia a primeira e uma das duas
        // ficava impossível de acordar ou de receber pagamento, sem nenhum aviso.
        $a = $this->obrigacaoCom(id: 7, valorOriginal: 100000, descricao: 'Abril/2026');
        $b = $this->obrigacaoCom(id: 9, valorOriginal: 100000, descricao: 'Abril/2026');

        $opcoes = AcordoCriarType::opcoesObrigacoes([$a, $b]);

        self::assertCount(2, $opcoes, 'obrigação idêntica não pode apagar obrigação');
        self::assertSame([7, 9], array_values($opcoes), 'cada opção resolve o SEU id');
        foreach (array_keys($opcoes) as $rotulo) {
            self::assertStringContainsString('Abril/2026', (string) $rotulo);
        }
    }

    #[Test]
    public function opcoesObrigacoesIdenticasComPagamentosDiferentesJaSeDistinguem(): void
    {
        // Aqui os rótulos já diferem pelo "já recebidos" — o desempate por id não entra, para não sujar
        // um rótulo que já é único.
        $a = $this->obrigacaoCom(id: 7, valorOriginal: 100000, descricao: 'Abril/2026');
        $b = $this->obrigacaoCom(id: 9, valorOriginal: 100000, descricao: 'Abril/2026');

        $opcoes = AcordoCriarType::opcoesObrigacoes([$a, $b], [9 => 25000]);

        self::assertCount(2, $opcoes);
        self::assertArrayHasKey('Abril/2026 — venc 10/04/2026 — R$ 1.000,00', $opcoes);
        self::assertArrayHasKey('Abril/2026 — venc 10/04/2026 — R$ 750,00 (R$ 250,00 já recebidos)', $opcoes);
    }

    #[Test]
    public function opcoesObrigacoesSuportaTresIdenticas(): void
    {
        $opcoes = AcordoCriarType::opcoesObrigacoes([
            $this->obrigacaoCom(id: 1, valorOriginal: 5000, descricao: 'Taxa'),
            $this->obrigacaoCom(id: 2, valorOriginal: 5000, descricao: 'Taxa'),
            $this->obrigacaoCom(id: 3, valorOriginal: 5000, descricao: 'Taxa'),
        ]);

        self::assertCount(3, $opcoes);
        self::assertSame([1, 2, 3], array_values($opcoes));
        self::assertCount(3, array_unique(array_keys($opcoes)), 'os três rótulos têm de ser distintos');
    }
}
