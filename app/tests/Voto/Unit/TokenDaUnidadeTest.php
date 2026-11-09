<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Service\TokenDaUnidade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenDaUnidade::class)]
final class TokenDaUnidadeTest extends TestCase
{
    #[TestDox('Semente nova: 32 hex, diferente a cada chamada')]
    public function testNovaSemente(): void
    {
        $servico = new TokenDaUnidade('segredo');

        $semente = $servico->novaSemente();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $semente);
        self::assertNotSame($semente, $servico->novaSemente());
    }

    #[TestDox('Token: 40 hex, o mesmo para a mesma semente, pela fórmula da seção 4')]
    public function testTokenDeterministico(): void
    {
        $servico = new TokenDaUnidade('segredo');
        $semente = str_repeat('ab', 16);

        $token = $servico->token($semente);

        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $token);
        self::assertSame($token, $servico->token($semente));
        self::assertSame(substr(hash_hmac('sha256', 'voto-assembleia:' . $semente, 'segredo'), 0, 40), $token);
        self::assertNotSame($token, $servico->token(str_repeat('cd', 16)));
    }

    #[TestDox('Outro segredo dá outro token: sem o segredo, a semente do banco não leva ao link')]
    public function testTokenDependeDoSegredo(): void
    {
        $semente = str_repeat('ab', 16);

        self::assertNotSame((new TokenDaUnidade('segredo'))->token($semente), (new TokenDaUnidade('outro'))->token($semente));
    }

    #[TestDox('Hash do token: sha256, 64 hex')]
    public function testHash(): void
    {
        $servico = new TokenDaUnidade('segredo');
        $token = $servico->token(str_repeat('ab', 16));

        $hash = $servico->hash($token);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(hash('sha256', $token), $hash);
    }
}
