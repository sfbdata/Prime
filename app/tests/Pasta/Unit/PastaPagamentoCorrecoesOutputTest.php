<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\CorrecaoDeValorDoPagamentoOutput;
use App\Pasta\DTO\PastaPagamentoLinhaOutput;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\Entity\PastaPagamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O "corrigido · era R$ a" da linha de pagamento (desenho 1.2.3, dc 3462):
 * o valor de ANTES da primeira correção e uma linha de histórico por correção.
 */
#[CoversClass(PastaPagamentoLinhaOutput::class)]
#[CoversClass(CorrecaoDeValorDoPagamentoOutput::class)]
#[CoversClass(PastaPagamentosOutput::class)]
final class PastaPagamentoCorrecoesOutputTest extends TestCase
{
    private function pagamento(string $valor): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setDescricao('Entrada');
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('2026-10-20'));

        return $pagamento;
    }

    #[TestDox('linha nunca corrigida não fala de correção, e o campo abre com o valor em pt-BR')]
    public function testSemCorrecao(): void
    {
        $linha = PastaPagamentoLinhaOutput::montar($this->pagamento('1300.00'), new \DateTimeImmutable('2026-10-06'));

        self::assertFalse($linha->foiCorrigido());
        self::assertSame('', $linha->valorOriginalFormatado());
        self::assertSame('', $linha->historicoDeCorrecoes());
        self::assertSame('1300,00', $linha->valorEditavel);
    }

    #[TestDox('"era" é o valor de antes da PRIMEIRA correção; o histórico lista todas, em ordem')]
    public function testComDuasCorrecoes(): void
    {
        $correcoes = [
            new CorrecaoDeValorDoPagamentoOutput(new \DateTimeImmutable('2026-10-01 09:05'), 'Mariana Costa', '1000.00', '1200.00'),
            new CorrecaoDeValorDoPagamentoOutput(new \DateTimeImmutable('2026-10-03 16:40'), 'Samuel Freitas', '1200.00', '1300.00'),
        ];

        $linha = PastaPagamentoLinhaOutput::montar($this->pagamento('1300.00'), new \DateTimeImmutable('2026-10-06'), $correcoes);

        self::assertTrue($linha->foiCorrigido());
        self::assertSame('R$ 1.000,00', $linha->valorOriginalFormatado());
        self::assertSame(
            "01/10/2026 09:05 · Mariana Costa: R$ 1.000,00 → R$ 1.200,00\n"
            . '03/10/2026 16:40 · Samuel Freitas: R$ 1.200,00 → R$ 1.300,00',
            $linha->historicoDeCorrecoes(),
        );
        self::assertSame('R$ 1.300,00', $linha->valorFormatado, 'o valor da linha é o atual, não o original');
    }

    #[TestDox('o card entrega a cada linha só as correções do SEU pagamento')]
    public function testCardDistribuiPorPagamento(): void
    {
        $a = $this->pagamento('100.00');
        $b = $this->pagamento('200.00');
        (new \ReflectionProperty(PastaPagamento::class, 'id'))->setValue($a, 11);
        (new \ReflectionProperty(PastaPagamento::class, 'id'))->setValue($b, 12);

        $saida = PastaPagamentosOutput::montar([$a, $b], new \DateTimeImmutable('2026-10-06'), [
            11 => [new CorrecaoDeValorDoPagamentoOutput(new \DateTimeImmutable('2026-10-01 10:00'), 'Ana', '90.00', '100.00')],
            99 => [new CorrecaoDeValorDoPagamentoOutput(new \DateTimeImmutable('2026-10-01 10:00'), 'Ana', '1.00', '2.00')],
        ]);

        self::assertTrue($saida->todos[0]->foiCorrigido());
        self::assertSame('R$ 90,00', $saida->todos[0]->valorOriginalFormatado());
        self::assertFalse($saida->todos[1]->foiCorrigido(), 'correção de outro pagamento não pode colar nesta linha');
    }
}
