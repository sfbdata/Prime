<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaPagamentoLinhaOutput;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\BaseDoPercentual;
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
#[CoversClass(BaseDoPercentual::class)]
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
        self::assertSame('R$ 0,00', $saida->contratadoFormatado);
        self::assertSame([], $saida->proximos);
    }

    #[TestDox('parcelas vencidas (não pagas, vencimento antes de hoje) entram na faixa vermelha do cartão')]
    public function testVencidas(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('1500.00', '2026-06-18', '2026-06-18'),
            $this->pagamento('1300.00', '2026-08-01'),
            $this->pagamento('200.00', '2026-08-10'),
            $this->pagamento('1300.00', '2026-08-28'),
        ], $this->hoje());

        self::assertSame(2, $saida->vencidasQuantidade);
        self::assertSame('R$ 1.500,00', $saida->vencidasFormatado);
        self::assertSame(0, PastaPagamentosOutput::montar([], $this->hoje())->vencidasQuantidade);
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
        self::assertSame('R$ 5.400,00', $saida->contratadoFormatado);
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

        self::assertSame('R$ 3.900,30', $saida->contratadoFormatado);
    }

    /**
     * TRÊS selos, como o desenho 1.2.3 mostra (dc 3438-3441): Pago (verde, tom
     * `ok`), Pendente (âmbar, tom `proximo`) e Vencida (vermelho, tom
     * `urgente`). Vencida continua DERIVADA — nada é gravado: é o vencimento
     * passado de um pagamento sem data de quitação.
     */
    #[TestDox('o estado é derivado das datas, não gravado: Pago, Vencida e Pendente')]
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
                PastaPagamentoLinhaOutput::ESTADO_VENCIDA,
                PastaPagamentoLinhaOutput::ESTADO_PENDENTE,
            ],
            array_map(fn (PastaPagamentoLinhaOutput $l) => $l->estado, $saida->todos)
        );

        self::assertSame(['ok', 'urgente', 'proximo'], array_map(fn ($l) => $l->tom, $saida->todos));

        // Vencido continua sendo "próximo" para o card: ainda não entrou.
        self::assertCount(2, $saida->proximos);
    }

    /**
     * A fronteira do Vencida: vencer HOJE ainda não é vencido (o dia não acabou),
     * vencer ONTEM já é. Sem este caso, um `<=` no lugar do `<` pintaria de
     * vermelho a parcela do dia para quem abre a tela de manhã.
     */
    #[TestDox('vence hoje é Pendente; venceu ontem é Vencida')]
    public function testFronteiraDoVencida(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('100.00', '2026-08-27'),
            $this->pagamento('100.00', '2026-08-28'),
        ], $this->hoje());

        self::assertSame(PastaPagamentoLinhaOutput::ESTADO_VENCIDA, $saida->todos[0]->estado);
        self::assertSame('urgente', $saida->todos[0]->tom);
        self::assertSame(PastaPagamentoLinhaOutput::ESTADO_PENDENTE, $saida->todos[1]->estado);
        self::assertSame('proximo', $saida->todos[1]->tom);
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

    #[TestDox('a linha de apoio mede a distância até o vencimento, nos termos do desenho')]
    public function testTextoDoVencimento(): void
    {
        // Entram na ordem em que o repositório entrega (vencimento ASC): ordenar
        // é dele, o DTO só preserva. Testar aqui uma ordenação que o DTO não faz
        // seria provar comportamento que não existe.
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('100.00', '2026-08-26'),
            $this->pagamento('100.00', '2026-08-27'),
            $this->pagamento('100.00', '2026-08-28'),
            $this->pagamento('100.00', '2026-08-29'),
            $this->pagamento('100.00', '2026-08-30'),
            $this->pagamento('100.00', '2026-09-04'),
            $this->pagamento('100.00', '2026-09-05'),
            $this->pagamento('100.00', '2026-12-25'),
        ], $this->hoje());

        $quando = array_map(fn (PastaPagamentoLinhaOutput $l) => $l->quando, $saida->todos);

        self::assertSame([
            'venceu 26/08/2026 · há 2 dias',
            'venceu 27/08/2026 · há 1 dia',
            'vence 28/08/2026 · hoje',
            // Sem "amanhã" (dc 3441): é "em 1 dia", no singular.
            'vence 29/08/2026 · em 1 dia',
            'vence 30/08/2026 · em 2 dias',
            'vence 04/09/2026 · em 7 dias',
            // Acima de 7 dias a contagem não ajuda mais: fica só a data.
            'vence 05/09/2026',
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

    // ── FPN-009: "· juros N% a.m." ────────────────────────────────────────

    #[TestDox('FPN-009: parcela com juros leva " · juros N% a.m." em qualquer estado; sem taxa (antiga, entrada, avulso) não leva')]
    public function testSufixoDeJuros(): void
    {
        $pendente = $this->pagamento('1084.34', '2026-09-28')->setJurosMensal('1.5000');
        $vencida  = $this->pagamento('1084.34', '2026-08-20')->setJurosMensal('1.5000');
        $paga     = $this->pagamento('1084.34', '2026-08-20', '2026-08-15')->setJurosMensal('1.5000');
        $antiga   = $this->pagamento('1084.34', '2026-09-28');

        $linhas = array_map(
            fn (PastaPagamento $p) => PastaPagamentoLinhaOutput::montar($p, $this->hoje())->quando,
            [$pendente, $vencida, $paga, $antiga],
        );

        self::assertSame([
            'vence 28/09/2026 · juros 1,5% a.m.',
            'venceu 20/08/2026 · há 8 dias · juros 1,5% a.m.',
            'pago em 15/08/2026 · juros 1,5% a.m.',
            'vence 28/09/2026',
        ], $linhas);
    }

    #[TestDox('FPN-009: a taxa sai sem zeros à direita e com vírgula (2 → "2", 0,99 → "0,99", 10 → "10"); zero não vira sufixo')]
    public function testFormatoDaTaxa(): void
    {
        $sufixo = fn (string $taxa): string => PastaPagamentoLinhaOutput::montar(
            $this->pagamento('100.00', '2026-12-01')->setJurosMensal($taxa),
            $this->hoje(),
        )->quando;

        self::assertSame('vence 01/12/2026 · juros 2% a.m.', $sufixo('2.0000'));
        self::assertSame('vence 01/12/2026 · juros 0,99% a.m.', $sufixo('0.9900'));
        self::assertSame('vence 01/12/2026 · juros 10% a.m.', $sufixo('10.0000'));
        self::assertSame('vence 01/12/2026 · juros 1,2345% a.m.', $sufixo('1.2345'));
        self::assertSame('vence 01/12/2026', $sufixo('0.0000'));
    }

    #[TestDox('FPN-009: a taxa NÃO entra em conta nenhuma — totais e valor da linha iguais com e sem ela')]
    public function testTaxaNaoMudaTotais(): void
    {
        $semTaxa = [$this->pagamento('1084.34', '2026-09-28'), $this->pagamento('2000.00', '2026-08-28', '2026-08-28')];
        $comTaxa = [$this->pagamento('1084.34', '2026-09-28')->setJurosMensal('1.5000'), $this->pagamento('2000.00', '2026-08-28', '2026-08-28')];

        $a = PastaPagamentosOutput::montar($semTaxa, $this->hoje());
        $b = PastaPagamentosOutput::montar($comTaxa, $this->hoje());

        self::assertSame(
            [$a->total, $a->quantidadePagos, $a->recebidoFormatado, $a->contratadoFormatado, $a->percentual, $a->todos[0]->valorFormatado],
            [$b->total, $b->quantidadePagos, $b->recebidoFormatado, $b->contratadoFormatado, $b->percentual, $b->todos[0]->valorFormatado],
        );
    }

    // ── Êxito e sucumbência: o previsto (spec pasta-exito-sucumbencia) ─────

    private function previsto(TipoDePagamento $tipo, string $percentual): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->preverPorPercentual($tipo, $percentual, '1.00');
        $p->setDescricao($tipo === TipoDePagamento::Exito ? 'Honorários de êxito' : 'Sucumbência estimada');

        return $p;
    }

    #[TestDox('o previsto fica FORA do contratado, do recebido e da barra; só aparece no "+ R$ X previsto em"')]
    public function testPrevistoForaDosTotais(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('1500.00', '2026-06-18', '2026-06-18'),
            $this->pagamento('1300.00', '2026-09-10'),
            $this->pagamento('1300.00', '2026-10-10'),
            $this->previsto(TipoDePagamento::Exito, '20'),
        ], $this->hoje());

        self::assertSame(4, $saida->total, 'a contagem do cabeçalho conta a linha prevista');
        self::assertSame('R$ 4.100,00', $saida->contratadoFormatado);
        self::assertSame(410000, $saida->contratadoCentavos);
        self::assertSame('R$ 1.500,00', $saida->recebidoFormatado);
        self::assertSame(37, $saida->percentual, '1500 de 4100, sem o previsto');
        self::assertSame('R$ 820,00', $saida->previstoFormatado, '20% de 4.100,00, calculado na leitura (o retrato gravado é ignorado)');
        self::assertSame('exito', $saida->previstoEm);
    }

    #[TestDox('"previsto em": êxito, sucumbência ou os dois')]
    public function testPrevistoEm(): void
    {
        $base = [$this->pagamento('1000.00', '2026-09-10')];

        self::assertSame('', PastaPagamentosOutput::montar($base, $this->hoje())->previstoEm);
        self::assertSame('sucumbencia', PastaPagamentosOutput::montar([...$base, $this->previsto(TipoDePagamento::Sucumbencia, '10')], $this->hoje())->previstoEm);
        $dois = PastaPagamentosOutput::montar([...$base, $this->previsto(TipoDePagamento::Sucumbencia, '10'), $this->previsto(TipoDePagamento::Exito, '20')], $this->hoje());
        self::assertSame('exito_sucumbencia', $dois->previstoEm);
        self::assertSame('R$ 300,00', $dois->previstoFormatado);
    }

    #[TestDox('linha prevista: selo azul "Previsto" e "N% sobre o total contratado · condicionado ao resultado"')]
    public function testLinhaPrevista(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->pagamento('4287.00', '2026-09-10'),
            $this->previsto(TipoDePagamento::Exito, '12.5'),
        ], $this->hoje());

        $linha = $saida->todos[1];
        self::assertSame(PastaPagamentoLinhaOutput::ESTADO_PREVISTO, $linha->estado);
        self::assertSame('previsto', $linha->tom);
        self::assertSame('12,5% sobre o total contratado · condicionado ao resultado', $linha->quando);
        self::assertSame('R$ 535,88', $linha->valorFormatado);
        self::assertSame('535,88', $linha->valorEditavel, 'a correção abre com o valor calculado');
        self::assertFalse($linha->pago);
    }

    #[TestDox('previsto corrigido (sem %) continua previsto: "condicionado ao resultado" com o valor fixo')]
    public function testPrevistoCorrigido(): void
    {
        $exito = $this->previsto(TipoDePagamento::Exito, '20');
        $exito->congelarValor('900.00');

        $saida = PastaPagamentosOutput::montar([$this->pagamento('1000.00', '2026-09-10'), $exito], $this->hoje());

        self::assertSame('condicionado ao resultado', $saida->todos[1]->quando);
        self::assertSame('R$ 900,00', $saida->todos[1]->valorFormatado);
        self::assertSame('R$ 1.000,00', $saida->contratadoFormatado);
        self::assertSame('R$ 900,00', $saida->previstoFormatado);
    }

    #[TestDox('ordem dos próximos: pendentes, depois previstos; pagos depois de tudo')]
    public function testOrdemPendentesPrevistosPagos(): void
    {
        $saida = PastaPagamentosOutput::montar([
            $this->previsto(TipoDePagamento::Sucumbencia, '10'),
            $this->pagamento('100.00', '2026-08-01', '2026-08-01'),
            $this->pagamento('100.00', '2026-08-20'),
            $this->pagamento('100.00', '2026-10-10'),
        ], $this->hoje());

        self::assertSame(
            [PastaPagamentoLinhaOutput::ESTADO_VENCIDA, PastaPagamentoLinhaOutput::ESTADO_PENDENTE, PastaPagamentoLinhaOutput::ESTADO_PREVISTO],
            array_map(static fn (PastaPagamentoLinhaOutput $l): string => $l->estado, $saida->proximos),
        );
    }

}
