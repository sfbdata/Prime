<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Service\ModelosDeAutomacao;
use App\Comercial\UseCase\ListarAutomacoesUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarAutomacoesUseCase::class)]
#[CoversClass(ModelosDeAutomacao::class)]
final class ListarAutomacoesUseCaseTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function enderecos(): iterable
    {
        yield 'comum' => ['ana.souza@escritorio.com.br', 'a***@escritorio.com.br'];
        yield 'uma letra' => ['a@b.co', 'a***@b.co'];
        yield 'sem arroba' => ['invalido', '***'];
    }

    #[TestDox('"Últimos disparos" mascara o destinatário (Q7, minimização): $esperado')]
    #[DataProvider('enderecos')]
    public function testMascara(string $email, string $esperado): void
    {
        self::assertSame($esperado, ListarAutomacoesUseCase::mascarar($email));
    }

    #[TestDox('{nome} vira o primeiro nome com a caixa de título; sem nome, "colega" (dc L.484-487)')]
    public function testPrimeiroNome(): void
    {
        self::assertSame('Ana', ModelosDeAutomacao::primeiroNome('ANA MARIA SOUZA'));
        self::assertSame('José', ModelosDeAutomacao::primeiroNome('  josé da silva'));
        self::assertSame('colega', ModelosDeAutomacao::primeiroNome(null));
        self::assertSame('colega', ModelosDeAutomacao::primeiroNome('   '));
    }
}
