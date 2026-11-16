<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\RegistroDeClientes;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\ValidarPedidoDeAutorizacaoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O pedido do `/oauth/authorize` (spec mcp-remoto-oauth 3.2, 3.4, 5.1 e 9.6; 12.7 parte A): cliente e retorno primeiro,
 * sem redirecionar; depois `response_type`, PKCE só S256 e `resource` (`invalid_target`), que voltam ao app.
 */
#[CoversClass(ValidarPedidoDeAutorizacaoUseCase::class)]
final class ValidarPedidoDeAutorizacaoUseCaseTest extends TestCase
{
    private const CLIENT_ID = 'https://claude.ai/oauth/mcp-client-metadata';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const DESAFIO = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var list<array{string, string}> */
    private array $consultas = [];

    private function sut(?ClienteRecusado $recusa = null): ValidarPedidoDeAutorizacaoUseCase
    {
        $registro = $this->createMock(RegistroDeClientes::class);
        $registro->method('resolver')->willReturnCallback(function (string $clientId, string $redirect) use ($recusa): ClienteDoConector {
            $this->consultas[] = [$clientId, $redirect];
            if ($recusa !== null) {
                throw $recusa;
            }

            return new ClienteDoConector($clientId, TipoDeClienteDoConector::Cimd, 'App', [$redirect], new \DateTimeImmutable());
        });

        return new ValidarPedidoDeAutorizacaoUseCase($registro);
    }

    /**
     * @param array<string, mixed> $trocas valor nulo tira o parâmetro
     *
     * @return array<string, mixed>
     */
    private static function pedido(array $trocas = []): array
    {
        $pedido = array_merge([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => self::DESAFIO,
            'code_challenge_method' => 'S256',
            'resource' => ServidorDeAutorizacao::RESOURCE,
            'scope' => 'mcp:leitura',
        ], $trocas);

        return array_filter($pedido, static fn (mixed $valor): bool => $valor !== null);
    }

    #[TestDox('pedido válido: o cliente e o retorno são conferidos no registro e o pedido sai com os parâmetros do app')]
    public function testPedidoValido(): void
    {
        $pedido = $this->sut()->executar(self::pedido());

        self::assertSame([[self::CLIENT_ID, self::REDIRECT]], $this->consultas);
        self::assertSame(self::CLIENT_ID, $pedido->clientId);
        self::assertSame(self::REDIRECT, $pedido->redirectUri);
        self::assertSame('xyz', $pedido->state);
        self::assertSame(self::DESAFIO, $pedido->codeChallenge);
        self::assertSame(ServidorDeAutorizacao::RESOURCE, $pedido->resource);
        self::assertSame(['mcp:leitura'], $pedido->escoposPedidos);
    }

    #[TestDox('sem state: o pedido segue, sem state')]
    public function testSemState(): void
    {
        self::assertNull($this->sut()->executar(self::pedido(['state' => null]))->state);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function clienteOuRetornoInvalido(): iterable
    {
        yield 'sem client_id' => [['client_id' => null], ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO];
        yield 'client_id vazio' => [['client_id' => ''], ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO];
        yield 'client_id em lista' => [['client_id' => [self::CLIENT_ID]], ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO];
        yield 'sem redirect_uri' => [['redirect_uri' => null], ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO];
    }

    /** @param array<string, mixed> $trocas */
    #[DataProvider('clienteOuRetornoInvalido')]
    #[TestDox('cliente ou retorno ausente recusa sem redirecionar ($_dataName), mesmo com o PKCE e o resource errados')]
    public function testClienteOuRetornoAusente(array $trocas, string $motivo): void
    {
        // PKCE e resource errados juntos: o erro de cliente tem de vir antes (RFC 6749 4.1.2.1).
        $trocas += ['code_challenge_method' => 'plain', 'resource' => 'https://outro.com/mcp'];

        try {
            $this->sut()->executar(self::pedido($trocas));
            self::fail('Pedido sem cliente ou retorno passou.');
        } catch (ClienteRecusado $recusa) {
            self::assertSame($motivo, $recusa->motivo);
        }
        self::assertSame([], $this->consultas, 'sem client_id ou redirect_uri, nem consulta o registro');
    }

    #[TestDox('o registro recusa o cliente: a recusa sobe como ClienteRecusado antes de qualquer erro de protocolo')]
    public function testRegistroRecusaAntesDoProtocolo(): void
    {
        $this->expectException(ClienteRecusado::class);

        $this->sut(new ClienteRecusado(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, 'claude.ai'))
            ->executar(self::pedido(['code_challenge_method' => null, 'resource' => null]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function errosDoProtocolo(): iterable
    {
        yield 'response_type token' => [['response_type' => 'token'], ErroOAuth::UNSUPPORTED_RESPONSE_TYPE];
        yield 'sem response_type' => [['response_type' => null], ErroOAuth::UNSUPPORTED_RESPONSE_TYPE];
        yield 'sem code_challenge_method' => [['code_challenge_method' => null], ErroOAuth::INVALID_REQUEST];
        yield 'code_challenge_method plain' => [['code_challenge_method' => 'plain'], ErroOAuth::INVALID_REQUEST];
        yield 'code_challenge_method s256 minúsculo' => [['code_challenge_method' => 's256'], ErroOAuth::INVALID_REQUEST];
        yield 'sem code_challenge' => [['code_challenge' => null], ErroOAuth::INVALID_REQUEST];
        yield 'code_challenge curto' => [['code_challenge' => 'abc'], ErroOAuth::INVALID_REQUEST];
        yield 'code_challenge com =' => [['code_challenge' => substr(self::DESAFIO, 0, 42) . '='], ErroOAuth::INVALID_REQUEST];
        yield 'sem resource' => [['resource' => null], ErroOAuth::INVALID_TARGET];
        yield 'resource com barra no fim' => [['resource' => ServidorDeAutorizacao::RESOURCE . '/'], ErroOAuth::INVALID_TARGET];
        yield 'resource de outro site' => [['resource' => 'https://outro.com/mcp'], ErroOAuth::INVALID_TARGET];
        yield 'resource em lista' => [['resource' => [ServidorDeAutorizacao::RESOURCE]], ErroOAuth::INVALID_TARGET];
    }

    /** @param array<string, mixed> $trocas */
    #[DataProvider('errosDoProtocolo')]
    #[TestDox('erro de protocolo com o cliente aceito ($_dataName): ErroOAuth com o código da norma')]
    public function testErrosDoProtocolo(array $trocas, string $codigo): void
    {
        try {
            $this->sut()->executar(self::pedido($trocas));
            self::fail('Pedido inválido passou.');
        } catch (ErroOAuth $erro) {
            self::assertSame($codigo, $erro->codigo);
        }
        self::assertCount(1, $this->consultas, 'o cliente foi conferido antes do erro de protocolo');
    }

    /** @return iterable<string, array{?string, list<string>}> */
    public static function escopos(): iterable
    {
        yield 'os três, fora de ordem' => ['offline_access mcp:escrita mcp:leitura', ['mcp:leitura', 'mcp:escrita', 'offline_access']];
        yield 'desconhecido é ignorado' => ['mcp:leitura admin mcp:tudo', ['mcp:leitura']];
        yield 'espaços a mais e repetição' => ['  mcp:escrita   mcp:escrita ', ['mcp:escrita']];
        yield 'sem scope' => [null, []];
        yield 'só desconhecidos' => ['openid profile', []];
    }

    /** @param list<string> $esperados */
    #[DataProvider('escopos')]
    #[TestDox('escopos ($_dataName): só os conhecidos, na ordem do servidor')]
    public function testEscopos(?string $scope, array $esperados): void
    {
        self::assertSame($esperados, $this->sut()->executar(self::pedido(['scope' => $scope]))->escoposPedidos);
    }
}
