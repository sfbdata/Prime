<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\DocumentoCimd;
use App\Conector\Exception\ClienteRecusado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A validação do documento CIMD (spec mcp-remoto-oauth 3.4; teste 12.12). */
#[CoversClass(DocumentoCimd::class)]
final class DocumentoCimdTest extends TestCase
{
    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';

    /** @param array<string, mixed> $dados */
    private static function json(array $dados): string
    {
        return json_encode($dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private static function valido(): array
    {
        return ['client_id' => self::URL, 'client_name' => ' Claude Code ', 'redirect_uris' => ['http://localhost/callback'], 'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code']];
    }

    #[TestDox('Documento válido: guarda client_id, nome sem espaços nas pontas, redirects e a validade')]
    public function testValido(): void
    {
        $validoAte = new \DateTimeImmutable('2026-11-16 13:00:00');

        $documento = DocumentoCimd::doCorpo(self::json(self::valido()), self::URL, $validoAte);

        self::assertSame(self::URL, $documento->clientId);
        self::assertSame('Claude Code', $documento->clientName);
        self::assertSame(['http://localhost/callback'], $documento->redirectUris);
        self::assertSame($validoAte, $documento->validoAte);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidos(): iterable
    {
        $base = self::valido();

        yield 'client_id diferente da URL' => [self::json(['client_id' => 'https://claude.ai/oauth/outro'] + $base)];
        yield 'client_id com barra a mais' => [self::json(['client_id' => self::URL . '/'] + $base)];
        yield 'client_id em maiúsculas' => [self::json(['client_id' => strtoupper(self::URL)] + $base)];
        yield 'sem client_id' => [self::json(array_diff_key($base, ['client_id' => 1]))];
        yield 'sem client_name' => [self::json(array_diff_key($base, ['client_name' => 1]))];
        yield 'client_name vazio' => [self::json(['client_name' => '  '] + $base)];
        yield 'client_name não é texto' => [self::json(['client_name' => ['Claude']] + $base)];
        yield 'client_name longo demais' => [self::json(['client_name' => str_repeat('a', 256)] + $base)];
        yield 'sem redirect_uris' => [self::json(array_diff_key($base, ['redirect_uris' => 1]))];
        yield 'redirect_uris vazio' => [self::json(['redirect_uris' => []] + $base)];
        yield 'redirect_uris texto' => [self::json(['redirect_uris' => 'http://localhost/callback'] + $base)];
        yield 'redirect_uris objeto' => [self::json(['redirect_uris' => ['a' => 'http://localhost/callback']] + $base)];
        yield 'redirect_uris com número' => [self::json(['redirect_uris' => ['http://localhost/callback', 7]] + $base)];
        yield 'redirect_uris com vazio' => [self::json(['redirect_uris' => ['']] + $base)];
        yield 'autenticação com segredo' => [self::json(['token_endpoint_auth_method' => 'client_secret_basic'] + $base)];
        yield 'autenticação private_key_jwt' => [self::json(['token_endpoint_auth_method' => 'private_key_jwt'] + $base)];
        yield 'autenticação nula' => [self::json(['token_endpoint_auth_method' => null] + $base)];
        yield 'JSON inválido' => ['{"client_id": "' . self::URL . '",'];
        yield 'JSON lista' => ['["' . self::URL . '"]'];
        yield 'JSON texto' => ['"' . self::URL . '"'];
        yield 'corpo vazio' => [''];
        yield 'JSON fundo demais' => [str_repeat('{"a":', 20) . '1' . str_repeat('}', 20)];
    }

    #[DataProvider('invalidos')]
    #[TestDox('Recusa como documento_invalido: $_dataName')]
    public function testInvalido(string $corpo): void
    {
        try {
            DocumentoCimd::doCorpo($corpo, self::URL, new \DateTimeImmutable());
            self::fail('aceitou um documento inválido');
        } catch (ClienteRecusado $e) {
            self::assertSame(ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO, $e->motivo);
            self::assertSame('claude.ai', $e->host);
        }
    }
}
