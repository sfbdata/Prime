<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\UseCase\CorrigirValorDoPagamentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Corrigir o valor de um pagamento da pasta. O histórico NÃO é gravado pelo
 * UseCase (sai do audit_log, no flush) — por isso o que se trava aqui é QUANDO
 * há flush: uma vez quando muda, nenhuma quando o valor é o mesmo ou não serve.
 */
#[CoversClass(CorrigirValorDoPagamentoUseCase::class)]
final class CorrigirValorDoPagamentoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CorrigirValorDoPagamentoUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new CorrigirValorDoPagamentoUseCase($this->em);
        $this->pasta   = new Pasta();
        $this->tenant  = new Tenant();
    }

    private function pagamento(string $valor = '1300.00', ?Pasta $pasta = null, ?Tenant $tenant = null): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta ?? $this->pasta);
        $pagamento->setTenant($tenant ?? $this->tenant);
        $pagamento->setDescricao('2ª parcela — honorários');
        $pagamento->setValor($valor);

        return $pagamento;
    }

    #[TestDox('grava o valor novo em decimal, com um flush só')]
    public function testCorrigeOValor(): void
    {
        $pagamento = $this->pagamento('1300.00');
        $this->em->expects($this->once())->method('flush');

        $alterado = $this->useCase->executar($pagamento, $this->pasta, $this->tenant, '1.450,50', '1.300,00');

        self::assertTrue($alterado);
        self::assertSame('1450.50', $pagamento->getValor(), 'dinheiro entra como decimal, nunca float');
    }

    /**
     * Sem isto o histórico ganharia uma linha "R$ a → R$ a": "1.300" e
     * "1300,00" são o mesmo dinheiro, e a comparação é em centavos.
     */
    #[TestDox('mesmo valor, escrito de outro jeito, não grava nada')]
    public function testMesmoValorNaoGrava(): void
    {
        $pagamento = $this->pagamento('1300.00');
        $this->em->expects($this->never())->method('flush');

        self::assertFalse($this->useCase->executar($pagamento, $this->pasta, $this->tenant, '1.300', '1.300,00'));
        self::assertSame('1300.00', $pagamento->getValor());
    }

    /** @return iterable<string, array{string}> */
    public static function valoresInvalidos(): iterable
    {
        yield 'zero'                 => ['0,00'];
        yield 'em branco'            => ['   '];
        yield 'negativo'             => ['-10,00'];
        yield 'texto'                => ['mil reais'];
        yield 'três casas decimais'  => ['10,505'];
        yield 'duas vírgulas'        => ['1,000,00'];
    }

    #[DataProvider('valoresInvalidos')]
    #[TestDox('recusa valor que não é reais maior que zero: $entrada')]
    public function testRecusaValorInvalido(string $entrada): void
    {
        $pagamento = $this->pagamento('1300.00');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($pagamento, $this->pasta, $this->tenant, $entrada, '1.300,00');
            self::fail('o valor "' . $entrada . '" deveria ter sido recusado');
        } catch (\InvalidArgumentException) {
            self::assertSame('1300.00', $pagamento->getValor(), 'valor recusado não pode ter tocado o pagamento');
        }
    }

    #[TestDox('pagamento de OUTRA pasta é recusado, mesmo que o controller o deixe passar')]
    public function testRecusaPagamentoDeOutraPasta(): void
    {
        $pagamento = $this->pagamento('1300.00', new Pasta());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        try {
            $this->useCase->executar($pagamento, $this->pasta, $this->tenant, '10,00', '1.300,00');
        } finally {
            self::assertSame('1300.00', $pagamento->getValor());
        }
    }

    #[TestDox('pagamento de OUTRO escritório é recusado')]
    public function testRecusaPagamentoDeOutroTenant(): void
    {
        $pagamento = $this->pagamento('1300.00', null, new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);

        $this->useCase->executar($pagamento, $this->pasta, $this->tenant, '10,00', '1.300,00');
    }

    /**
     * Perda de atualização: o valor que a tela mostrou não é mais o do banco
     * (outra pessoa corrigiu). Nada muda, nada é gravado, e a mensagem diz o
     * valor que vale agora.
     */
    #[TestDox('valor exibido diferente do gravado: recusa sem gravar e informa o valor atual')]
    public function testValorAnteriorDesatualizadoRecusa(): void
    {
        $pagamento = $this->pagamento('1450.00');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($pagamento, $this->pasta, $this->tenant, '900,00', '1.300,00');
            self::fail('a correção sobre um valor desatualizado deveria ter sido recusada');
        } catch (\UnexpectedValueException $e) {
            self::assertSame(
                'O valor foi alterado por outra pessoa (agora R$ 1.450,00). Recarregue e confira antes de corrigir.',
                $e->getMessage(),
            );
            self::assertSame('1450.00', $pagamento->getValor());
        }
    }

    #[TestDox('valor exibido é comparado em centavos: "1300" vale 1.300,00')]
    public function testValorAnteriorEmCentavos(): void
    {
        $pagamento = $this->pagamento('1300.00');
        $this->em->expects($this->once())->method('flush');

        self::assertTrue($this->useCase->executar($pagamento, $this->pasta, $this->tenant, '900,00', '1300'));
        self::assertSame('900.00', $pagamento->getValor());
    }

    /** @return iterable<string, array{string}> */
    public static function valoresAnterioresInvalidos(): iterable
    {
        yield 'vazio'     => [''];
        yield 'em branco' => ['   '];
        yield 'texto'     => ['abc'];
    }

    #[DataProvider('valoresAnterioresInvalidos')]
    #[TestDox('sem o valor exibido ($anterior) recusa como entrada inválida, sem gravar')]
    public function testSemValorAnteriorRecusa(string $anterior): void
    {
        $pagamento = $this->pagamento('1300.00');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($pagamento, $this->pasta, $this->tenant, '900,00', $anterior);
            self::fail('sem o valor exibido a correção não pode ser gravada às cegas');
        } catch (\InvalidArgumentException) {
            self::assertSame('1300.00', $pagamento->getValor());
        }
    }
}
