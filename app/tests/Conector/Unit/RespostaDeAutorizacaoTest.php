<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\RespostaDeAutorizacao;
use App\Conector\Service\ServidorDeAutorizacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * O redirecionamento ao app no fim do `/oauth/authorize` (RFC 6749 4.1.2, RFC 9207; spec mcp-remoto-oauth 3.2 e 9.6;
 * 12.7): `iss` com o texto exato em TODO redirecionamento, de sucesso e de erro, e o `state` do pedido.
 */
#[CoversClass(RespostaDeAutorizacao::class)]
final class RespostaDeAutorizacaoTest extends TestCase
{
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    /** @return array<string, string> */
    private static function parametros(RedirectResponse $resposta): array
    {
        $alvo = $resposta->getTargetUrl();
        parse_str((string) parse_url($alvo, \PHP_URL_QUERY), $parametros);

        /** @var array<string, string> $parametros */
        return $parametros;
    }

    #[TestDox('sucesso: code, state e iss exato https://bluejus.com.br, sem cache nem Referer')]
    public function testSucesso(): void
    {
        $resposta = RespostaDeAutorizacao::sucesso(self::REDIRECT, 'codigo-1', 'estado com espaço&e=1');

        self::assertStringStartsWith(self::REDIRECT . '?', $resposta->getTargetUrl());
        self::assertSame(['code' => 'codigo-1', 'state' => 'estado com espaço&e=1', 'iss' => 'https://bluejus.com.br'], self::parametros($resposta));
        self::assertSame(ServidorDeAutorizacao::ISSUER, self::parametros($resposta)['iss']);
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
    }

    #[TestDox('erro: error, error_description, state e o mesmo iss exato')]
    public function testErroLevaIss(): void
    {
        $resposta = RespostaDeAutorizacao::erro(self::REDIRECT, ErroOAuth::invalidTarget(), 'xyz');

        self::assertSame([
            'error' => 'invalid_target',
            'error_description' => ErroOAuth::invalidTarget()->descricao,
            'state' => 'xyz',
            'iss' => 'https://bluejus.com.br',
        ], self::parametros($resposta));
    }

    #[TestDox('sem state no pedido, a resposta não inventa um; o iss vai mesmo assim')]
    public function testSemState(): void
    {
        $parametros = self::parametros(RespostaDeAutorizacao::erro(self::REDIRECT, ErroOAuth::accessDenied(), null));

        self::assertArrayNotHasKey('state', $parametros);
        self::assertSame(ServidorDeAutorizacao::ISSUER, $parametros['iss']);
    }

    #[TestDox('redirect_uri com query própria: a query fica e os parâmetros vão depois')]
    public function testRedirectComQuery(): void
    {
        $resposta = RespostaDeAutorizacao::sucesso('http://127.0.0.1:5000/callback?app=1', 'c', 's');

        self::assertSame('http://127.0.0.1:5000/callback?app=1&code=c&state=s&iss=https%3A%2F%2Fbluejus.com.br', $resposta->getTargetUrl());
    }
}
