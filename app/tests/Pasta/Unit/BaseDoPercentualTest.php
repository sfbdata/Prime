<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\BaseDoPercentual;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A base do percentual de êxito e sucumbência (decisão C8 do dono, 23/10/2026): o "Total
 * contratado", a soma das linhas NÃO previstas, pagas ou não. O previsto fica fora dela.
 */
#[CoversClass(BaseDoPercentual::class)]
final class BaseDoPercentualTest extends TestCase
{
    private function linha(TipoDePagamento $tipo, string $valor, bool $pago = false): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->setTipo($tipo);
        $p->setValor($valor);
        if ($pago) {
            $p->alternarQuitacao(new \DateTimeImmutable('2026-10-01'));
        }

        return $p;
    }

    private function previsto(TipoDePagamento $tipo, string $percentual, string $retrato = '0.00'): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->preverPorPercentual($tipo, $percentual, $retrato);

        return $p;
    }

    #[TestDox('soma contrato e custas, pagos ou não; o previsto fica fora')]
    public function testSomaSoONaoPrevisto(): void
    {
        $base = new BaseDoPercentual();

        $centavos = $base->centavosDaBase([
            $this->linha(TipoDePagamento::Contrato, '1500.00', pago: true),
            $this->linha(TipoDePagamento::Contrato, '1300.00'),
            $this->linha(TipoDePagamento::Custas, '1300.00'),
            $this->previsto(TipoDePagamento::Exito, '20', '999.00'),
            $this->previsto(TipoDePagamento::Sucumbencia, '10'),
        ]);

        self::assertSame(410000, $centavos);
    }

    #[TestDox('êxito já recebido (congelado) deixa de ser previsto e entra na soma, como no desenho')]
    public function testExitoRecebidoEntra(): void
    {
        $exito = $this->previsto(TipoDePagamento::Exito, '20');
        $exito->congelarValor('500.00');
        $exito->alternarQuitacao(new \DateTimeImmutable('2026-10-02'));

        $centavos = (new BaseDoPercentual())->centavosDaBase([
            $this->linha(TipoDePagamento::Contrato, '1000.00'),
            $exito,
        ]);

        self::assertSame(150000, $centavos);
    }

    #[TestDox('o valor do previsto é calculado na leitura: round(base × pct) / 100, meio para cima')]
    public function testValorDoPrevisto(): void
    {
        $base   = new BaseDoPercentual();
        $exito  = $this->previsto(TipoDePagamento::Exito, '12.5', '1.00');
        $fixo   = $this->linha(TipoDePagamento::Contrato, '1300.00');

        self::assertSame(53588, $base->centavosDe($exito, 428700), '12,5% de 4.287,00 = 535,875 → 535,88');
        self::assertSame(130000, $base->centavosDe($fixo, 428700), 'sem percentual vale o valor gravado');
    }

    #[TestDox('daPasta lê os lançamentos da própria pasta; valorCalculado devolve decimal')]
    public function testDaPasta(): void
    {
        $pasta = new Pasta();
        $contrato = $this->linha(TipoDePagamento::Contrato, '10000.00');
        $exito    = $this->previsto(TipoDePagamento::Exito, '20');
        foreach ([$contrato, $exito] as $p) {
            $p->setPasta($pasta);
            $pasta->getPagamentos()->add($p);
        }

        $base = new BaseDoPercentual();

        self::assertSame(1000000, $base->daPasta($pasta));
        self::assertSame('2000.00', $base->valorCalculado($exito));
        self::assertSame('10000.00', $base->valorCalculado($contrato));
    }

    #[TestDox('sem nenhum lançamento contratado a base é zero e o previsto vale R$ 0,00')]
    public function testBaseVazia(): void
    {
        $base = new BaseDoPercentual();

        self::assertSame(0, $base->centavosDaBase([]));
        self::assertSame(0, $base->centavosDe($this->previsto(TipoDePagamento::Sucumbencia, '10'), 0));
    }
}
