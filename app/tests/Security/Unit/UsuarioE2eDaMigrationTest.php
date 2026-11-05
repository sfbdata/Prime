<?php

declare(strict_types=1);

namespace App\Tests\Security\Unit;

use DoctrineMigrations\Version20260508170000;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A migration `Version20260508170000` cria o Master de testes e2e (auditoria 4.07). A trava antiga olhava só o
 * `getenv('APP_ENV')`, que não enxerga o APP_ENV vindo do .env: a nova só semeia em banco de teste/e2e pelo nome ou
 * com a variável real E2E_SEMEAR_USUARIO=1. E a senha do usuário não pode aparecer no arquivo.
 */
#[CoversNothing]
final class UsuarioE2eDaMigrationTest extends TestCase
{
    private const ARQUIVO = __DIR__ . '/../../../migrations/Version20260508170000.php';

    /** sha256 da senha do usuário e2e em minúsculas e o tamanho dela: o literal não entra no teste. */
    private const SENHA_E2E_SHA256 = 'ca9b8ab1a4e3cda9f40c0f0197216ce1093af82757b5a2bbb088d2dc1d5c6d3e';
    private const SENHA_E2E_TAMANHO = 21;

    public static function setUpBeforeClass(): void
    {
        require_once self::ARQUIVO;
    }

    /** @return iterable<string, array{string, string|false, bool}> */
    public static function casos(): iterable
    {
        yield 'banco de produção' => ['saas', false, false];
        yield 'banco de dev' => ['saas_ux', false, false];
        yield 'nome parecido não basta' => ['saas_testes', false, false];
        yield 'variável diferente de 1' => ['saas', 'true', false];
        yield 'variável vazia' => ['saas', '', false];
        yield 'banco de teste' => ['saas_test', false, true];
        yield 'banco de teste do paratest' => ['saas_test3', false, true];
        yield 'banco de e2e' => ['saas_e2e', false, true];
        yield 'variável real E2E_SEMEAR_USUARIO=1' => ['saas', '1', true];
    }

    #[DataProvider('casos')]
    #[TestDox('Semeia o usuário e2e só em banco de teste/e2e ou com E2E_SEMEAR_USUARIO=1: $_dataName')]
    public function testTrava(string $banco, string|false $variavel, bool $esperado): void
    {
        self::assertSame($esperado, Version20260508170000::podeSemearUsuarioE2e($banco, $variavel));
    }

    #[TestDox('A trava não depende mais do getenv(APP_ENV)')]
    public function testNaoUsaMaisAppEnv(): void
    {
        // Sem assertStringNotContainsString: a falha imprimiria o arquivo inteiro.
        self::assertFalse(str_contains((string) file_get_contents(self::ARQUIVO), "getenv('APP_ENV')"));
    }

    #[TestDox('A senha do usuário e2e não aparece no arquivo da migration')]
    public function testSenhaForaDoArquivo(): void
    {
        $texto = mb_strtolower((string) file_get_contents(self::ARQUIVO));
        $achou = false;
        for ($i = 0, $fim = strlen($texto) - self::SENHA_E2E_TAMANHO; $i <= $fim; ++$i) {
            if (hash('sha256', substr($texto, $i, self::SENHA_E2E_TAMANHO)) === self::SENHA_E2E_SHA256) {
                $achou = true;
                break;
            }
        }

        self::assertFalse($achou, 'A senha do usuário e2e voltou ao arquivo da migration.');
    }
}
