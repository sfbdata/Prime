<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Security\AutenticadorDoConector;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * O autenticador do firewall `conector_mcp` (spec mcp-remoto-oauth 8; decisão 2 do PR 5): entra só com bearer, aceita a
 * pessoa da concessão do token pelo mesmo validador do SDK, e na recusa não responde nada (o 401 é do SDK).
 */
#[CoversClass(AutenticadorDoConector::class)]
final class AutenticadorDoConectorTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): AutenticadorDoConector
    {
        // Com o validador do teste: no container o autenticador é embutido no firewall.
        return new AutenticadorDoConector($this->validador());
    }

    private static function pedido(?string $authorization): Request
    {
        $pedido = Request::create('/mcp', 'POST');
        if ($authorization !== null) {
            $pedido->headers->set('Authorization', $authorization);
        }

        return $pedido;
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function cabecalhos(): iterable
    {
        yield 'sem Authorization' => [null, false];
        yield 'Basic' => ['Basic dXNlcjpzZW5oYQ==', false];
        yield 'Bearerx colado' => ['Bearerabc', false];
        yield 'Bearer' => ['Bearer abc', true];
        yield 'bearer minúsculo' => ['bearer abc', true];
        yield 'Bearer sem token' => ['Bearer', true];
    }

    #[DataProvider('cabecalhos')]
    #[TestDox('supports com $authorization: $esperado')]
    public function testSoEntraComBearer(?string $authorization, bool $esperado): void
    {
        self::assertSame($esperado, $this->sut()->supports(self::pedido($authorization)));
    }

    #[TestDox('token de acesso em ordem: o passaporte traz a pessoa da concessão, e o validador guarda o aceite para o SDK')]
    public function testAceitaAPessoaDoToken(): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);

        $passaporte = $this->sut()->authenticate(self::pedido('Bearer ' . $bearer));

        self::assertSame($conexao['pessoa']->getId(), $passaporte->getUser()->getId());
        self::assertSame($conexao['concessao']->getId(), $this->validador()->tokenValidado()?->getConcessao()->getId());
        self::assertTrue($this->validador()->validate($bearer)->isAllowed(), 'o SDK lê o mesmo julgamento');
    }

    #[TestDox('token recusado ou cabeçalho malformado: falha de autenticação, e a falha não responde nada')]
    public function testRecusaSemResponder(): void
    {
        $conexao = $this->conexaoAutorizada();
        $renovacao = $this->tokenDeRenovacao($conexao['concessao']);

        foreach (['Bearer ' . $renovacao, 'Bearer', 'Bearer a b'] as $cabecalho) {
            try {
                $this->sut()->authenticate(self::pedido($cabecalho));
                self::fail('autenticou com ' . $cabecalho);
            } catch (AuthenticationException $falha) {
                self::assertNull($this->sut()->onAuthenticationFailure(self::pedido($cabecalho), $falha));
            }
        }
        self::assertNull($this->validador()->tokenValidado());
    }
}
