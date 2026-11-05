<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\UseCase\ConfirmarCodigoSenhaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * O limitador `senha_codigo_erros_conta` do framework.yaml é o mesmo número da constante TETO_DA_CONTA (auditoria
 * 3.14). Os outros testes trocam o serviço por uma fábrica montada da constante; este prende o yaml de verdade, com a
 * trava `lock.recuperacao_codigo.factory`, e o aviso a um por dia.
 */
#[CoversClass(ConfirmarCodigoSenhaUseCase::class)]
final class TetoDaContaConfiguradoTest extends KernelTestCase
{
    #[TestDox('O limitador configurado aceita TETO_DA_CONTA tentativas por conta e recusa a seguinte')]
    public function testLimitadorDoYamlCasaComAConstante(): void
    {
        $limitador = $this->fabrica('limiter.senha_codigo_erros_conta')->create('conta-' . uniqid());

        for ($i = 0; $i < ConfirmarCodigoSenhaUseCase::TETO_DA_CONTA; ++$i) {
            self::assertTrue($limitador->consume()->isAccepted(), 'tentativa ' . ($i + 1));
        }
        self::assertFalse($limitador->consume()->isAccepted());
    }

    #[TestDox('O aviso do teto sai no máximo uma vez por conta no período')]
    public function testAvisoUmPorDia(): void
    {
        $limitador = $this->fabrica('limiter.senha_codigo_aviso_teto')->create('conta-' . uniqid());

        self::assertTrue($limitador->consume()->isAccepted());
        self::assertFalse($limitador->consume()->isAccepted());
    }

    private function fabrica(string $id): RateLimiterFactoryInterface
    {
        $fabrica = static::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $fabrica);

        return $fabrica;
    }
}
