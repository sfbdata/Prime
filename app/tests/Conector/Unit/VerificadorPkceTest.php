<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\VerificadorPkce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** PKCE só S256 (RFC 7636, 4.1, 4.6 e apêndice B; spec mcp-remoto-oauth 9.6). */
#[CoversClass(VerificadorPkce::class)]
final class VerificadorPkceTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    #[TestDox('O exemplo do apêndice B da RFC 7636 confere')]
    public function testExemploDaRfc(): void
    {
        self::assertSame(self::CHALLENGE, VerificadorPkce::desafioDe(self::VERIFIER));
        self::assertTrue((new VerificadorPkce())->confere(self::VERIFIER, self::CHALLENGE));
    }

    /** @return iterable<string, array{string}> */
    public static function verifiersRecusados(): iterable
    {
        yield 'outro verifier' => [str_repeat('a', 43)];
        yield '42 caracteres' => [substr(self::VERIFIER, 0, 42)];
        yield '129 caracteres' => [str_repeat('a', 129)];
        yield 'caractere fora da lista' => [substr(self::VERIFIER, 0, 42) . '+'];
        yield 'o próprio challenge (plain)' => [self::CHALLENGE];
        yield 'vazio' => [''];
    }

    #[DataProvider('verifiersRecusados')]
    #[TestDox('Recusa: $_dataName')]
    public function testRecusa(string $verifier): void
    {
        self::assertFalse((new VerificadorPkce())->confere($verifier, self::CHALLENGE));
    }

    #[TestDox('Aceita de 43 a 128 caracteres de [A-Za-z0-9-._~]')]
    public function testLimitesDoFormato(): void
    {
        $pkce = new VerificadorPkce();
        foreach ([str_repeat('a', 43), str_repeat('-._~', 32)] as $verifier) {
            self::assertTrue($pkce->confere($verifier, VerificadorPkce::desafioDe($verifier)), (string) \strlen($verifier));
        }
    }
}
