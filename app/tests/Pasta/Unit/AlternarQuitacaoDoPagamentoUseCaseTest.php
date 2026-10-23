<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Quitar e desfazer. O que importa aqui é o êxito e a sucumbência (decisão 3 do dono): ao
 * "Marcar como recebido", o valor calculado naquele instante é GRAVADO e o percentual zera.
 */
#[CoversClass(AlternarQuitacaoDoPagamentoUseCase::class)]
final class AlternarQuitacaoDoPagamentoUseCaseTest extends TestCase
{
    private AlternarQuitacaoDoPagamentoUseCase $useCase;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');
        $this->useCase = new AlternarQuitacaoDoPagamentoUseCase($em, new BaseDoPercentual());
        $this->pasta   = new Pasta();
    }

    private function naPasta(PastaPagamento $p): PastaPagamento
    {
        $p->setPasta($this->pasta);
        $this->pasta->getPagamentos()->add($p);

        return $p;
    }

    private function contrato(string $valor): PastaPagamento
    {
        $p = new PastaPagamento();
        $p->setValor($valor);

        return $this->naPasta($p);
    }

    #[TestDox('quitar um previsto grava o valor calculado (20% de 12.860,00) e zera o percentual')]
    public function testQuitarCongela(): void
    {
        $this->contrato('10000.00');
        $this->contrato('2860.00');
        $exito = new PastaPagamento();
        $exito->preverPorPercentual(TipoDePagamento::Exito, '20', '100.00');
        $this->naPasta($exito);

        $this->useCase->executar($exito, new \DateTimeImmutable('2026-10-23'));

        self::assertTrue($exito->estaPago());
        self::assertSame('2572.00', $exito->getValor(), 'o retrato antigo (100,00) é trocado pelo valor do momento');
        self::assertNull($exito->getPercentual());
        self::assertFalse($exito->estaPrevisto());
    }

    #[TestDox('desfazer o recebimento de um êxito congelado volta a ser previsto, com o valor fixo')]
    public function testDesfazerMantemValorFixo(): void
    {
        $this->contrato('1000.00');
        $exito = new PastaPagamento();
        $exito->preverPorPercentual(TipoDePagamento::Exito, '50', '0.00');
        $this->naPasta($exito);

        $this->useCase->executar($exito);
        $this->useCase->executar($exito);

        self::assertFalse($exito->estaPago());
        self::assertTrue($exito->estaPrevisto());
        self::assertSame('500.00', $exito->getValor());
        self::assertNull($exito->getPercentual());
    }

    #[TestDox('quitar contrato não mexe no valor')]
    public function testContratoNaoMuda(): void
    {
        $p = $this->contrato('1300.00');

        $this->useCase->executar($p);

        self::assertTrue($p->estaPago());
        self::assertSame('1300.00', $p->getValor());
    }
}
