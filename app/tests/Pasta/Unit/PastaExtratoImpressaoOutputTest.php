<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaExtratoImpressaoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PastaExtratoImpressaoOutput::class)]
final class PastaExtratoImpressaoOutputTest extends TestCase
{
    private function pagamento(string $descricao, string $valor, string $vencimento, ?string $pagoEm = null): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->setDescricao($descricao);
        $p->setValor($valor);
        $p->setVencimento(new \DateTimeImmutable($vencimento));
        if ($pagoEm !== null) {
            $p->alternarQuitacao(new \DateTimeImmutable($pagoEm));
        }

        return $p;
    }

    #[TestDox('Totais em centavos (Contratado, Recebido, A receber) e uma linha por lançamento com a situação do desenho')]
    public function testTotaisELinhas(): void
    {
        $extrato = PastaExtratoImpressaoOutput::montar(new Pasta(), [
            $this->pagamento('Entrada', '1000.10', '2026-09-01', '2026-09-02'),
            $this->pagamento('2ª parcela', '300.20', '2026-09-20'),
            $this->pagamento('3ª parcela', '300.20', '2026-11-20'),
        ], new \DateTimeImmutable('2026-10-05'));

        self::assertSame('R$ 1.600,50', $extrato->contratadoFormatado);
        self::assertSame('R$ 1.000,10', $extrato->recebidoFormatado);
        self::assertSame('R$ 600,40', $extrato->aReceberFormatado);

        self::assertSame(['Recebido em 02/09/2026', 'Vencido', 'A receber'], array_column($extrato->linhas, 'situacao'));
        self::assertSame(['01/09/2026', '20/09/2026', '20/11/2026'], array_column($extrato->linhas, 'vencimento'));
        self::assertSame(['ok', 'urgente', 'proximo'], array_column($extrato->linhas, 'tom'));
        // A linha do extrato é a MESMA da folha `#psExtratoFolha` (PastaPagamentoLinhaOutput::quando).
        self::assertSame('pago em 02/09/2026', $extrato->linhas[0]['quando']);
        self::assertSame('R$ 300,20', $extrato->linhas[1]['valor']);
    }

    #[TestDox('Sem lançamento: tudo zero e nenhuma linha (o filtro que remove tudo)')]
    public function testSemLancamento(): void
    {
        $extrato = PastaExtratoImpressaoOutput::montar(new Pasta(), []);

        self::assertSame([], $extrato->linhas);
        self::assertSame('R$ 0,00', $extrato->contratadoFormatado);
        self::assertSame('R$ 0,00', $extrato->aReceberFormatado);
    }

    #[TestDox('Contrato: pró-bono, pendente de assinatura ou assinado — os rótulos do desenho')]
    public function testContrato(): void
    {
        $pasta = new Pasta(); // nasce PENDENTE
        self::assertSame('Contrato pendente de assinatura', PastaExtratoImpressaoOutput::montar($pasta, [])->contrato);

        $pasta->setSituacaoContrato('REGULAR');
        self::assertSame('Contrato assinado', PastaExtratoImpressaoOutput::montar($pasta, [])->contrato);

        $pasta->setProBono(true);
        self::assertSame('Pró-bono', PastaExtratoImpressaoOutput::montar($pasta, [])->contrato);
    }
}
