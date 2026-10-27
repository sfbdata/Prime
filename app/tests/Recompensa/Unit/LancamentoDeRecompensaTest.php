<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LancamentoDeRecompensa::class)]
final class LancamentoDeRecompensaTest extends TestCase
{
    private const CHAVE = '11111111-2222-4333-8444-555555555555';

    private function novo(int $valor = 1000, ?string $obs = 'Ajuste', string $tipo = LancamentoDeRecompensa::TIPO_BONUS): LancamentoDeRecompensa
    {
        return new LancamentoDeRecompensa(Pessoas::usuario(1), $tipo, $valor, $obs, self::CHAVE, Pessoas::master(), new \DateTimeImmutable('2026-10-26 12:00:00'));
    }

    #[TestDox('crédito e estorno guardam o valor com sinal; observação é aparada e vazia vira nula')]
    public function testCriaComSinal(): void
    {
        $credito = $this->novo(2550, '  Convênio  ');
        $estorno = $this->novo(-300, '   ');

        self::assertSame(2550, $credito->getValorCentavos());
        self::assertFalse($credito->ehEstorno());
        self::assertSame('Convênio', $credito->getObservacao());
        self::assertSame(-300, $estorno->getValorCentavos());
        self::assertTrue($estorno->ehEstorno());
        self::assertNull($estorno->getObservacao());
        self::assertSame(self::CHAVE, $credito->getChaveDeIdempotencia());
        self::assertSame('bonus', $credito->getTipo());
        self::assertNull($credito->getId());
    }

    /** @return iterable<string, array{int}> */
    public static function valoresRecusados(): iterable
    {
        yield 'zero' => [0];
        yield 'acima do teto' => [LancamentoDeRecompensa::VALOR_MAXIMO + 1];
        yield 'abaixo do teto negativo' => [-LancamentoDeRecompensa::VALOR_MAXIMO - 1];
    }

    #[DataProvider('valoresRecusados')]
    #[TestDox('recusa valor zero e acima de R$ 100.000,00 em módulo')]
    public function testRecusaValor(int $valor): void
    {
        $this->expectException(RecompensaRecusada::class);
        $this->novo($valor);
    }

    #[TestDox('o teto exato, para os dois lados, é aceito')]
    public function testTetoExato(): void
    {
        self::assertSame(10000000, $this->novo(10000000)->getValorCentavos());
        self::assertSame(-10000000, $this->novo(-10000000)->getValorCentavos());
    }

    #[TestDox('recusa observação com mais de 255 caracteres e tipo que não existe')]
    public function testRecusaObservacaoETipo(): void
    {
        self::assertSame(255, mb_strlen((string) $this->novo(100, str_repeat('á', 255))->getObservacao()));
        foreach ([fn () => $this->novo(100, str_repeat('a', 256)), fn () => $this->novo(100, null, 'estornado')] as $i => $criar) {
            try {
                $criar();
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('é um livro: a entidade não tem setter e o repositório não tem remoção')]
    public function testImutavel(): void
    {
        foreach ((new \ReflectionClass(LancamentoDeRecompensa::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            self::assertDoesNotMatchRegularExpression('/^(set|alterar|definir|remover|registrar|atualizar)/', $metodo->getName(), $metodo->getName());
        }
        foreach ((new \ReflectionClass(LancamentoDeRecompensaRepository::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            self::assertDoesNotMatchRegularExpression('/remov|delet|exclu|apag/i', $metodo->getName(), $metodo->getName());
        }
        $atributos = (new \ReflectionClass(LancamentoDeRecompensa::class))->getAttributes(\Doctrine\ORM\Mapping\Entity::class);
        self::assertTrue($atributos[0]->newInstance()->readOnly, 'o Doctrine nunca emite UPDATE');
    }
}
