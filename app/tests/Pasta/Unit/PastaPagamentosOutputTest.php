<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaPagamentoLinhaOutput;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\Entity\PastaPagamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os totais do card Pagamentos. O "hoje" é sempre injetado nos testes — data do
 * relógio dentro de um teste é como se ganha suíte que quebra sozinha de um dia
 * para o outro.
 */
#[CoversClass(PastaPagamentosOutput::class)]
#[CoversClass(PastaPagamentoLinhaOutput::class)]
final class PastaPagamentosOutputTest extends TestCase
{
    private const HOJE = '2026-08-28';

    private function hoje(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::HOJE);
    }

    private function pagamento(string $valor, string $vencimento, ?string $pagoEm = null): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setDescricao('parcela');
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable($vencimento));

        if ($pagoEm !== null) {
            $pagamento->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }

        return $pagamento;
    }

    #[TestDox('sem pagamento nenhum, tudo é zero e a barra fica vazia — 0 de 0 não é 100%')]
    public function testListaVazia(): void
    {
        $saida = PastaPagamentosOutput::montar([], $this->hoje());

        self::assertSame(0, $saida->total);
        self::assertSame(0, $saida->percentual, 'nada previsto não pode virar barra cheia');
        self::assertSame('R$ 0,00', $saida->recebidoFormatado);
        self::assertSame('R$ 0,00', $saida->previstoFormatado);
        self::assertSame([], $saida->proximos);
    }

    #[TestDox('previsto soma tudo; recebido soma só o que tem data de pagamento')]
    public function testTotais(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('1500.00', '2026-06-18', '2026-06-18'),
            $this->pagamento('1300.00', '2026-08-28'),
            $this->pagamento('1300.00', '2026-09-10'),
            $this->pagamento('1300.00', '2026-10-10'),
        ], $this->hoje());

        self::assertSame(4, $saida->total);
        self::assertSame(1, $saida->quantidadePagos);
        self::assertSame('R$ 1.500,00', $saida->recebidoFormatado);
        self::assertSame('R$ 5.400,00', $saida->previstoFormatado);
        self::assertSame(28, $saida->percentual, '1500 de 5400 é 27,8% — arredonda para 28');
        self::assertCount(3, $saida->proximos, 'os "próximos" são os que ainda não entraram');
        self::assertCount(4, $saida->todos);
    }

    /**
     * A PROVA de que a soma não passa por float: três parcelas de 1.300,10
     * somadas como float dariam 3900.2999999999997, e o `number_format` do
     * formatador esconderia isso arredondando — até o total divergir da conta
     * que o humano faz olhando as três linhas.
     */
    #[TestDox('soma centavos quebrados sem perder um centavo')]
    public function testSomaNaoPerdeCentavo(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('1300.10', '2026-09-01'),
            $this->pagamento('1300.10', '2026-10-01'),
            $this->pagamento('1300.10', '2026-11-01'),
        ], $this->hoje());

        self::assertSame('R$ 3.900,30', $saida->previstoFormatado);
    }

    /**
     * DOIS selos, como o desenho aprovado mostra: Pendente (âmbar, tom
     * `proximo`) e Pago (verde, tom `ok`). O atrasado NÃO ganha um terceiro
     * selo — ele continua sendo Pendente, e o atraso aparece na linha de apoio.
     */
    #[TestDox('o estado é derivado da data de pagamento, não gravado: só Pago e Pendente')]
    public function testEstadosDerivados(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('100.00', '2026-08-01', '2026-08-05'),
            $this->pagamento('100.00', '2026-08-20'),
            $this->pagamento('100.00', '2026-09-10'),
        ], $this->hoje());

        self::assertSame(
            [
                PastaPagamentoLinhaOutput::ESTADO_PAGO,
                PastaPagamentoLinhaOutput::ESTADO_PENDENTE,
                PastaPagamentoLinhaOutput::ESTADO_PENDENTE,
            ],
            array_map(fn (PastaPagamentoLinhaOutput $l) => $l->estado, $saida->todos)
        );

        self::assertSame(['ok', 'proximo', 'proximo'], array_map(fn ($l) => $l->tom, $saida->todos));

        // O atraso não some: muda a linha de apoio, não o selo.
        self::assertStringContainsString('atrasado', $saida->todos[1]->quando);
    }

    /**
     * Quitação atrasada continua sendo quitação: um pagamento que venceu em
     * julho e entrou em agosto está PAGO, não vencido. Gravar o estado numa
     * coluna seria a forma de deixar essas duas informações discordarem.
     */
    #[TestDox('pagamento quitado com atraso não volta a ser vencido')]
    public function testPagoComAtrasoNaoEhVencido(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('100.00', '2026-07-01', '2026-08-15'),
        ], $this->hoje());

        self::assertSame(PastaPagamentoLinhaOutput::ESTADO_PAGO, $saida->todos[0]->estado);
        self::assertSame('pago em 15/08/2026', $saida->todos[0]->quando, 'quitado fala de quando entrou');
    }

    #[TestDox('a linha de apoio mede a distância até o vencimento, em português')]
    public function testTextoDoVencimento(): void
    {
        // Entram na ordem em que o repositório entrega (vencimento ASC): ordenar
        // é dele, o DTO só preserva. Testar aqui uma ordenação que o DTO não faz
        // seria provar comportamento que não existe.
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('100.00', '2026-08-26'),
            $this->pagamento('100.00', '2026-08-28'),
            $this->pagamento('100.00', '2026-08-29'),
            $this->pagamento('100.00', '2026-08-30'),
            $this->pagamento('100.00', '2026-12-25'),
        ], $this->hoje());

        $quando = array_map(fn (PastaPagamentoLinhaOutput $l) => $l->quando, $saida->todos);

        self::assertSame([
            'vence 26/08/2026 · atrasado 2 dias',
            'vence 28/08/2026 · hoje',
            'vence 29/08/2026 · amanhã',
            'vence 30/08/2026 · em 2 dias',
            // Acima de 30 dias a contagem não ajuda mais: fica só a data.
            'vence 25/12/2026',
        ], $quando);
    }

    #[TestDox('tudo pago: barra cheia e nenhum próximo vencimento')]
    public function testTudoPago(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('700.00', '2026-07-01', '2026-07-01'),
            $this->pagamento('300.00', '2026-08-01', '2026-08-01'),
        ], $this->hoje());

        self::assertSame(100, $saida->percentual);
        self::assertSame('R$ 1.000,00', $saida->recebidoFormatado);
        self::assertSame([], $saida->proximos);
    }
}
