<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Exception\ErroOAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErroOAuth::class)]
final class ErroOAuthTest extends TestCase
{
    /** @return iterable<string, array{ErroOAuth, string, int}> */
    public static function fabricas(): iterable
    {
        yield 'invalid_request' => [ErroOAuth::invalidRequest(), 'invalid_request', 400];
        yield 'invalid_client' => [ErroOAuth::invalidClient(), 'invalid_client', 401];
        yield 'invalid_grant' => [ErroOAuth::invalidGrant(), 'invalid_grant', 400];
        yield 'invalid_target' => [ErroOAuth::invalidTarget(), 'invalid_target', 400];
        yield 'invalid_scope' => [ErroOAuth::invalidScope(), 'invalid_scope', 400];
        yield 'unsupported_grant_type' => [ErroOAuth::unsupportedGrantType(), 'unsupported_grant_type', 400];
        yield 'unsupported_response_type' => [ErroOAuth::unsupportedResponseType(), 'unsupported_response_type', 400];
        yield 'access_denied' => [ErroOAuth::accessDenied(), 'access_denied', 400];
    }

    #[DataProvider('fabricas')]
    #[TestDox('$_dataName: código da norma, descrição fixa não vazia e status HTTP do /oauth/token')]
    public function testFabrica(ErroOAuth $erro, string $codigo, int $status): void
    {
        self::assertSame($codigo, $erro->codigo);
        self::assertNotSame('', $erro->descricao);
        self::assertSame($status, $erro->statusHttp());
        self::assertSame(['error' => $codigo, 'error_description' => $erro->descricao], $erro->paraResposta());
    }

    #[TestDox('Quem lança pode trocar a descrição por outro texto fixo; o código não muda')]
    public function testDescricaoPropria(): void
    {
        $erro = ErroOAuth::invalidRequest('PKCE com S256 é obrigatório.');

        self::assertSame(['error' => 'invalid_request', 'error_description' => 'PKCE com S256 é obrigatório.'], $erro->paraResposta());
    }
}
