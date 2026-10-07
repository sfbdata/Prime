<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ParcelamentoDaPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\CalculadoraDeParcelamento;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Mesmo padrão do `RegistrarPagamentoDaPastaUseCaseTest`: o EntityManager é
 * mock, e o que se confere é o que foi entregue a ele — quantas linhas, com que
 * valor, descrição e vencimento — e que houve UM flush (ou nenhum, no erro).
 */
#[CoversClass(RegistrarParcelamentoDaPastaUseCase::class)]
#[CoversClass(CalculadoraDeParcelamento::class)]
final class RegistrarParcelamentoDaPastaUseCaseTest extends TestCase
{
    private const HOJE = '2026-10-06 15:30:00';

    private EntityManagerInterface&MockObject $em;
    private RegistrarParcelamentoDaPastaUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    /** @var list<PastaPagamento> */
    private array $persistidos = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            self::assertInstanceOf(PastaPagamento::class, $o);
            $this->persistidos[] = $o;
        });

        $this->useCase = new RegistrarParcelamentoDaPastaUseCase(
            $this->em,
            new CalculadoraDeParcelamento(),
            new MockClock(self::HOJE),
        );

        $this->tenant = new Tenant();
        $this->autor  = new User();
        $this->pasta  = new Pasta();
        $this->pasta->setTenant($this->tenant);
    }

    /** @param array<string, string|bool> $campos */
    private function input(array $campos = []): ParcelamentoDaPastaInput
    {
        $c = $campos + [
            'tipo'        => 'contrato',
            'base'        => 'valor',
            'total'       => '12.000,00',
            'percentual'  => '20',
            'entrada'     => '',
            'parcelas'    => '1',
            'vencimento'  => '2026-11-06',
            'juros'       => false,
            'taxa'        => '1',
            'descricao'   => '',
        ];

        return new ParcelamentoDaPastaInput(
            tipo: (string) $c['tipo'],
            base: (string) $c['base'],
            valorTotal: (string) $c['total'],
            percentual: (string) $c['percentual'],
            entrada: (string) $c['entrada'],
            parcelas: (string) $c['parcelas'],
            primeiroVencimento: (string) $c['vencimento'],
            comJuros: (bool) $c['juros'],
            taxaMensal: (string) $c['taxa'],
            descricao: (string) $c['descricao'],
        );
    }

    /** @return list<array{string, string, string, ?string}> descrição, valor, vencimento, pago em */
    private function linhas(): array
    {
        return array_map(static fn (PastaPagamento $p): array => [
            $p->getDescricao(),
            $p->getValor(),
            $p->getVencimento()->format('Y-m-d'),
            $p->getPagoEm()?->format('Y-m-d'),
        ], $this->persistidos);
    }

    #[TestDox('entrada + 10 parcelas sem juros: 11 linhas PENDENTES, um flush, a soma é o total')]
    public function testEntradaMaisParcelas(): void
    {
        $this->em->expects($this->once())->method('flush');

        $saida = $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'entrada'  => '2.000,00',
            'parcelas' => '10',
        ]));

        $linhas = $this->linhas();
        self::assertCount(11, $linhas);
        self::assertSame(['Entrada · honorários', '2000.00', '2026-10-06', null], $linhas[0], 'entrada vence hoje e nasce PENDENTE');
        self::assertSame(['1ª parcela · honorários', '1000.00', '2026-11-06', null], $linhas[1]);
        self::assertSame(['10ª parcela · honorários', '1000.00', '2027-08-06', null], $linhas[10]);
        self::assertSame(11, $saida->quantidade);
        self::assertSame('12000.00', $saida->totalLancado);

        foreach ($this->persistidos as $p) {
            self::assertSame($this->pasta, $p->getPasta());
            self::assertSame($this->tenant, $p->getTenant());
            self::assertSame($this->autor, $p->getAutor());
        }
    }

    /**
     * Regra financeira do dono (07/10/2026): "nenhuma operação pode registrar
     * recebimento financeiro que não ocorreu". Vale para a entrada também — com
     * ou sem juros, de contrato ou de custas.
     *
     * @param array<string, string|bool> $campos
     */
    #[TestDox('entrada e TODAS as parcelas nascem pendentes, sempre (nenhum recebimento automático)')]
    #[DataProvider('cenariosComEntrada')]
    public function testNadaNasceRecebido(array $campos): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input($campos));

        self::assertNotEmpty($this->persistidos);
        foreach ($this->persistidos as $p) {
            self::assertNull($p->getPagoEm(), $p->getDescricao() . ' não pode nascer recebida');
            self::assertFalse($p->estaPago());
        }
    }

    /** @return iterable<string, array{array<string, string|bool>}> */
    public static function cenariosComEntrada(): iterable
    {
        yield 'contrato, entrada + 2'       => [['entrada' => '500', 'parcelas' => '2']];
        yield 'custas, entrada + 3'         => [['tipo' => 'custas', 'total' => '900', 'entrada' => '300', 'parcelas' => '3']];
        yield 'com juros, entrada + 12'     => [['total' => '10.000,00', 'entrada' => '1.000,00', 'parcelas' => '12', 'juros' => true, 'taxa' => '1']];
        yield 'entrada = total (só ela)'    => [['total' => '1.000,00', 'entrada' => '1.000,00', 'parcelas' => '5']];
    }

    #[TestDox('n = 1 sem entrada: uma linha só, "Honorários contratuais" ou "Custas e despesas"')]
    public function testUmaParcelaDescricao(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input(['total' => '850,50']));
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input(['total' => '120', 'tipo' => 'custas']));

        self::assertSame([
            ['Honorários contratuais', '850.50', '2026-11-06', null],
            ['Custas e despesas', '120.00', '2026-11-06', null],
        ], $this->linhas());
    }

    #[TestDox('custas parceladas usam o rótulo "custas" na entrada e nas parcelas')]
    public function testRotuloDeCustas(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'tipo' => 'custas', 'total' => '300', 'entrada' => '100', 'parcelas' => '2',
        ]));

        self::assertSame(['Entrada · custas', '1ª parcela · custas', '2ª parcela · custas'], array_column($this->linhas(), 0));
    }

    #[TestDox('com juros: o servidor calcula a Price (10 mil a 1% em 12x = 11 × 888,49 + 888,46)')]
    public function testComJuros(): void
    {
        $saida = $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '10.000,00', 'parcelas' => '12', 'juros' => true, 'taxa' => '1',
        ]));

        $valores = array_column($this->linhas(), 1);
        self::assertSame(array_merge(array_fill(0, 11, '888.49'), ['888.46']), $valores);
        self::assertSame('10661.85', $saida->totalLancado);
    }

    #[TestDox('juros desligado ignora a taxa digitada, mesmo inválida')]
    public function testSemJurosIgnoraTaxa(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '100', 'parcelas' => '3', 'juros' => false, 'taxa' => 'qualquer coisa',
        ]));

        self::assertSame(['33.33', '33.33', '33.34'], array_column($this->linhas(), 1));
    }

    #[TestDox('31/01 como 1º vencimento: a 2ª vence 28/02 e a 3ª volta para 31/03')]
    public function testFimDeMes(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '300', 'parcelas' => '3', 'vencimento' => '2027-01-31',
        ]));

        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_column($this->linhas(), 2));
    }

    #[TestDox('base % da causa usa o valor da causa da PASTA: 20% de 12.860,00 = 2.572,00')]
    public function testPercentualDaCausa(): void
    {
        $this->pasta->setValorCausa('12860.00');

        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'base' => 'pct', 'percentual' => '20', 'total' => 'ignorado', 'parcelas' => '2',
        ]));

        self::assertSame(['1286.00', '1286.00'], array_column($this->linhas(), 1));
    }

    #[TestDox('entrada maior que o total vira o total (como no desenho): uma linha, sem parcelas')]
    public function testEntradaMaiorQueTotal(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '1.000,00', 'entrada' => '5.000,00', 'parcelas' => '5',
        ]));

        self::assertSame([['Entrada · honorários', '1000.00', '2026-10-06', null]], $this->linhas());
    }

    #[TestDox('60 parcelas é o teto (o mesmo do campo do desenho) e passa')]
    public function testTetoDeParcelas(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '60.000,00', 'parcelas' => '60',
        ]));

        self::assertSame(60, RegistrarParcelamentoDaPastaUseCase::MAX_PARCELAS);
        self::assertCount(60, $this->persistidos);
    }

    #[TestDox('descrição livre vai em todas as linhas: " · k/N" nas parcelas e " · entrada" na entrada')]
    public function testDescricaoLivreEmTodasAsLinhas(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '300', 'entrada' => '100', 'parcelas' => '2', 'descricao' => '  Contrato   de março ',
        ]));

        self::assertSame(
            ['Contrato de março · entrada', 'Contrato de março · 1/2', 'Contrato de março · 2/2'],
            array_column($this->linhas(), 0),
        );
    }

    #[TestDox('descrição livre com uma parcela só vai sem sufixo; em branco valem as do desenho')]
    public function testDescricaoLivreUmaParcela(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input(['descricao' => 'Acordo extrajudicial']));
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input(['descricao' => '   ']));

        self::assertSame(['Acordo extrajudicial', 'Honorários contratuais'], array_column($this->linhas(), 0));
    }

    #[TestDox('taxa no teto (10% a.m.) passa')]
    public function testTaxaNoTeto(): void
    {
        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input([
            'total' => '1000', 'parcelas' => '2', 'juros' => true, 'taxa' => '10',
        ]));

        self::assertCount(2, $this->persistidos);
    }

    /** @return iterable<string, array{array<string, string|bool>}> */
    public static function camposInvalidos(): iterable
    {
        yield 'êxito ainda não'          => [['tipo' => 'exito']];
        yield 'sucumbência ainda não'    => [['tipo' => 'sucumbencia']];
        yield 'tipo desconhecido'        => [['tipo' => 'outro']];
        yield 'base desconhecida'        => [['base' => 'x']];
        yield 'total vazio'              => [['total' => '']];
        yield 'total zero'               => [['total' => '0,00']];
        yield 'total não é dinheiro'     => [['total' => 'abc']];
        yield 'total negativo'           => [['total' => '-100']];
        yield 'entrada não é dinheiro'   => [['entrada' => '1,2,3']];
        yield 'zero parcelas'            => [['parcelas' => '0']];
        yield 'acima do teto (61)'       => [['parcelas' => '61']];
        yield 'descrição longa demais'   => [['descricao' => str_repeat('a', 111)]];
        yield 'parcelas não é número'    => [['parcelas' => 'dez']];
        yield 'parcelas fracionária'     => [['parcelas' => '2.5']];
        yield 'vencimento vazio'         => [['vencimento' => '']];
        yield 'vencimento inexistente'   => [['vencimento' => '2027-02-31']];
        yield 'vencimento em pt-BR'      => [['vencimento' => '06/11/2026']];
        yield 'taxa zero com juros'      => [['juros' => true, 'taxa' => '0']];
        yield 'taxa acima do teto'       => [['juros' => true, 'taxa' => '10,01']];
        yield 'taxa não é número'        => [['juros' => true, 'taxa' => 'um']];
        yield 'percentual zero'          => [['base' => 'pct', 'percentual' => '0']];
        yield 'percentual acima de 100'  => [['base' => 'pct', 'percentual' => '101']];
        yield 'pequeno demais'           => [['total' => '0,50', 'parcelas' => '60']];
    }

    /** @param array<string, string|bool> $campos */
    #[DataProvider('camposInvalidos')]
    #[TestDox('campo inválido não grava NADA: nenhum persist, nenhum flush ($_dataName)')]
    public function testCampoInvalidoNaoGravaNada(array $campos): void
    {
        $this->pasta->setValorCausa('12860.00');
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input($campos));
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->persistidos, 'tudo ou nada: nenhuma linha entregue ao Doctrine');
        }
    }

    #[TestDox('base % sem valor da causa na pasta é recusada')]
    public function testPercentualSemValorDaCausa(): void
    {
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valor da causa');

        $this->useCase->executar($this->pasta, $this->autor, $this->tenant, $this->input(['base' => 'pct']));
    }

    #[TestDox('pasta de OUTRO escritório: DomainException e nada gravado')]
    public function testPastaDeOutroTenant(): void
    {
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\DomainException::class);

        try {
            $this->useCase->executar($this->pasta, $this->autor, new Tenant(), $this->input());
        } finally {
            self::assertSame([], $this->persistidos);
        }
    }
}
