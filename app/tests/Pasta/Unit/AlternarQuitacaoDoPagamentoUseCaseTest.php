<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use App\Pasta\Service\LancamentosDaPastaSobTrava;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
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
    private EntityManagerInterface&MockObject $em;
    /** O que o banco devolve sob a trava; `null` = os lançamentos da própria pasta. */
    private ?array $doBanco = null;
    /** @var list<string> */
    private array $ordem = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        foreach (['beginTransaction', 'flush', 'commit', 'rollback'] as $metodo) {
            $this->em->method($metodo)->willReturnCallback(function () use ($metodo): void {
                $this->ordem[] = $metodo;
            });
        }
        $this->pasta = new Pasta();
        $trava       = new class ($this) implements LancamentosDaPastaSobTrava {
            public function __construct(private readonly AlternarQuitacaoDoPagamentoUseCaseTest $teste)
            {
            }

            public function travarPastaELerLancamentos(Pasta $pasta): array
            {
                return $this->teste->sobTrava($pasta);
            }
        };
        $this->useCase = new AlternarQuitacaoDoPagamentoUseCase($this->em, new BaseDoPercentual(), $trava);
    }

    /** @return list<PastaPagamento> */
    public function sobTrava(Pasta $pasta): array
    {
        $this->ordem[] = 'trava';

        return $this->doBanco ?? array_values($pasta->getPagamentos()->toArray());
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

    #[TestDox('o previsto congela sob a trava da pasta: transação, trava, flush, commit, nessa ordem')]
    public function testCongelaSobATrava(): void
    {
        $this->contrato('1000.00');
        $exito = new PastaPagamento();
        $exito->preverPorPercentual(TipoDePagamento::Exito, '10', '0.00');
        $this->naPasta($exito);

        $this->useCase->executar($exito);

        self::assertSame(['beginTransaction', 'trava', 'flush', 'commit'], $this->ordem);
    }

    #[TestDox('a base é a relida do banco sob a trava, não a da coleção carregada no request')]
    public function testBaseRelidaSobATrava(): void
    {
        $this->contrato('1000.00');
        $exito = new PastaPagamento();
        $exito->preverPorPercentual(TipoDePagamento::Exito, '10', '0.00');
        $this->naPasta($exito);
        $corrigido = new PastaPagamento();
        $corrigido->setValor('3000.00');
        $this->doBanco = [$corrigido, $exito];

        $this->useCase->executar($exito);

        self::assertSame('300.00', $exito->getValor(), '10% de 3.000,00 (o banco), não de 1.000,00 (a memória)');
    }

    #[TestDox('total contratado zerado: receber o previsto é recusado, nada muda e a transação desfaz')]
    public function testBaseZeroRecusa(): void
    {
        $exito = new PastaPagamento();
        $exito->preverPorPercentual(TipoDePagamento::Exito, '20', '0.00');
        $this->naPasta($exito);

        try {
            $this->useCase->executar($exito);
            self::fail('deveria recusar');
        } catch (\DomainException $e) {
            self::assertStringContainsString('total contratado está zerado', $e->getMessage());
        }

        self::assertFalse($exito->estaPago());
        self::assertSame('20.00', $exito->getPercentual());
        self::assertSame(['beginTransaction', 'trava', 'rollback'], $this->ordem);
    }

    #[TestDox('contrato e desfazer não abrem transação nem travam a pasta')]
    public function testSemPercentualNaoTrava(): void
    {
        $p = $this->contrato('1300.00');

        $this->useCase->executar($p);

        self::assertSame(['flush'], $this->ordem);
    }
}
