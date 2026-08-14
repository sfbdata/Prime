<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\AtualizarValorCausaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarValorCausaUseCase::class)]
final class AtualizarValorCausaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AtualizarValorCausaUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new AtualizarValorCausaUseCase($this->em);
    }

    /**
     * O valor é dinheiro: nunca passa por float em lugar nenhum do caminho.
     * A entrada vem como o humano digita (pt-BR) e sai como o Doctrine grava
     * em decimal(15,2) — string com ponto decimal e sempre duas casas.
     *
     * @return array<string, array{string, string}>
     */
    public static function entradasValidas(): array
    {
        return [
            'ponto de milhar e vírgula decimal' => ['12.860,00', '12860.00'],
            'sem separador de milhar'           => ['12860,00', '12860.00'],
            'com o símbolo da moeda'            => ['R$ 12.860,00', '12860.00'],
            'só o milhar, sem decimais'         => ['12.860', '12860.00'],
            'inteiro puro'                      => ['12860', '12860.00'],
            'ponto decimal (teclado numérico)'  => ['12860.50', '12860.50'],
            'milhão com centavos'               => ['1.234.567,89', '1234567.89'],
            'uma casa decimal completa a outra' => ['12860,5', '12860.50'],
            'zero é valor legítimo'             => ['0,00', '0.00'],
            'zeros à esquerda somem'            => ['007,50', '7.50'],
            'espaços em volta'                  => ['  12860,00  ', '12860.00'],
            'teto da coluna decimal(15,2)'      => ['9999999999999,99', '9999999999999.99'],
        ];
    }

    #[DataProvider('entradasValidas')]
    #[TestDox('grava o valor digitado em pt-BR como decimal')]
    public function testGravaValorValido(string $digitado, string $esperado): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $digitado);

        self::assertSame($esperado, $pasta->getValorCausa());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function entradasVazias(): array
    {
        return [
            'string vazia'       => [''],
            'só espaços'         => ['   '],
            'nulo'               => [null],
            'só o símbolo da moeda' => ['R$'],
        ];
    }

    #[DataProvider('entradasVazias')]
    #[TestDox('entrada vazia limpa o valor — nulo não é zero')]
    public function testEntradaVaziaLimpaOValor(?string $digitado): void
    {
        $pasta = new Pasta();
        $pasta->setValorCausa('12860.00');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $digitado);

        self::assertNull(
            $pasta->getValorCausa(),
            'campo em branco tem de zerar o cadastro, não gravar R$ 0,00'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entradasInvalidas(): array
    {
        return [
            'letras'                    => ['abc'],
            'letras no meio do número'  => ['128a60'],
            'negativo'                  => ['-5000,00'],
            'negativo com moeda'        => ['R$ -1,00'],
            'três casas decimais'       => ['12860,999'],
            'duas vírgulas'             => ['1,2,3'],
            'acima do teto da coluna'   => ['99999999999999,00'],
            'só o separador'            => [','],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    #[TestDox('entrada inválida é recusada e nada é gravado')]
    public function testEntradaInvalidaLancaExcecao(string $digitado): void
    {
        $pasta = new Pasta();
        $pasta->setValorCausa('100.00');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($pasta, $digitado);
        } finally {
            self::assertSame(
                '100.00',
                $pasta->getValorCausa(),
                'entrada recusada não pode deixar a entidade suja'
            );
        }
    }

    #[TestDox('três casas decimais são recusadas em vez de arredondadas em silêncio')]
    public function testNaoArredondaEmSilencio(): void
    {
        $pasta = new Pasta();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duas casas');

        $this->useCase->executar($pasta, '12860,999');
    }
}
