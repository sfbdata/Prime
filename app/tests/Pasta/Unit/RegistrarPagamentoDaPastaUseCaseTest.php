<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\UseCase\RegistrarPagamentoDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarPagamentoDaPastaUseCase::class)]
final class RegistrarPagamentoDaPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RegistrarPagamentoDaPastaUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RegistrarPagamentoDaPastaUseCase($this->em);

        $this->pasta  = new Pasta();
        $this->autor  = new User();
        $this->tenant = new Tenant();
    }

    private function registrar(
        string $descricao = '2ª parcela — honorários',
        string $valor = '1.300,00',
        string $vencimento = '2026-09-10',
    ): PastaPagamento {
        return $this->useCase->executar(
            $this->pasta,
            $this->autor,
            $this->tenant,
            $descricao,
            $valor,
            $vencimento,
        );
    }

    #[TestDox('grava o pagamento com valor em decimal, vencimento sem hora e sempre PENDENTE')]
    public function testGravaPagamentoValido(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $pagamento = $this->registrar();

        self::assertSame('2ª parcela — honorários', $pagamento->getDescricao());
        self::assertSame('1300.00', $pagamento->getValor(), 'dinheiro entra como decimal, não como float');
        self::assertSame('2026-09-10', $pagamento->getVencimento()->format('Y-m-d'));
        self::assertSame($this->pasta, $pagamento->getPasta());
        self::assertSame($this->tenant, $pagamento->getTenant());
        self::assertSame($this->autor, $pagamento->getAutor());

        // Nasce pendente: quitar é OUTRO gesto, e lançar já quitado esconderia
        // a data em que o dinheiro realmente entrou.
        self::assertFalse($pagamento->estaPago());
        self::assertNull($pagamento->getPagoEm());
    }

    /**
     * A hora zerada importa: com a hora do lançamento embutida, um pagamento que
     * vence HOJE apareceria como vencido para quem abrisse a tela mais cedo no
     * mesmo dia — a comparação erraria justamente no dia da borda.
     */
    #[TestDox('o vencimento nasce com a hora zerada, não com a hora do lançamento')]
    public function testVencimentoNasceSemHora(): void
    {
        $pagamento = $this->registrar(vencimento: '2026-09-10');

        self::assertSame('00:00:00', $pagamento->getVencimento()->format('H:i:s'));
    }

    #[TestDox('espaços em volta da descrição não entram no banco')]
    public function testDescricaoEhAparada(): void
    {
        self::assertSame('Custas iniciais', $this->registrar(descricao: '  Custas iniciais  ')->getDescricao());
    }

    /** @return array<string, array{string}> */
    public static function valoresRecusados(): array
    {
        return [
            'zero não é pagamento'      => ['0,00'],
            'negativo'                  => ['-100,00'],
            'em branco'                 => [''],
            'só espaços'                => ['   '],
            'texto'                     => ['mil reais'],
            'três casas decimais'       => ['100,005'],
        ];
    }

    #[DataProvider('valoresRecusados')]
    #[TestDox('recusa valor que não é dinheiro maior que zero')]
    public function testRecusaValorInvalido(string $valor): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->registrar(valor: $valor);
    }

    #[TestDox('recusa lançamento sem descrição — uma linha de dinheiro anônima não serve para nada')]
    public function testRecusaDescricaoVazia(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Descreva');
        $this->registrar(descricao: '   ');
    }

    #[TestDox('recusa descrição acima do que a coluna comporta, em vez de truncar em silêncio')]
    public function testRecusaDescricaoLonga(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('120 caracteres');
        $this->registrar(descricao: str_repeat('a', 121));
    }

    /** @return array<string, array{string}> */
    public static function datasRecusadas(): array
    {
        return [
            'formato brasileiro'      => ['10/09/2026'],
            'texto'                   => ['amanhã'],
            'vazia'                   => [''],
            'mês inexistente'         => ['2026-13-01'],
            // 31/02 NÃO devolve false no createFromFormat: rola para 03/03 em
            // silêncio. Sem a volta ao texto, o vencimento gravado seria outro.
            'dia que não existe no mês' => ['2026-02-31'],
        ];
    }

    #[DataProvider('datasRecusadas')]
    #[TestDox('recusa vencimento que não é uma data real, em vez de inventar uma')]
    public function testRecusaVencimentoInvalido(string $vencimento): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data de vencimento');
        $this->registrar(vencimento: $vencimento);
    }

    #[TestDox('aceita vencimento no passado: lançar uma parcela já atrasada é caso normal')]
    public function testAceitaVencimentoNoPassado(): void
    {
        $pagamento = $this->registrar(vencimento: '2020-01-15');

        self::assertSame('2020-01-15', $pagamento->getVencimento()->format('Y-m-d'));
        self::assertTrue($pagamento->estaVencido(), 'pendente com data no passado é vencido');
    }
}
