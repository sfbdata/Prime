<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A gravação que o "Gerar links e enviar" faz antes do envio leva `envio=1`, para o servidor não registrar "Rascunho
 * salvo" (decisão de 10/11/2026 sobre o desenho 17, L374). Lido no JS como texto: não há navegador no PHPUnit.
 */
#[CoversNothing]
final class GravacaoDoEnvioJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/assinatura.js';

    #[TestDox('o gravar() do envio marca envio=1; o "Salvar rascunho" não marca')]
    public function testSoOEnvioMarca(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('async function gravar(destino, botao, depois, doEnvio) {', $js);
        self::assertStringContainsString("if (doEnvio) { corpo.append('envio', '1'); }", $js);
        self::assertSame(1, substr_count($js, "append('envio'"), 'só a gravação do envio marca');

        $enviar = substr($js, (int) strpos($js, 'function enviar(botao)'));
        $enviar = substr($enviar, 0, (int) strpos($enviar, "\n    }\n"));
        self::assertStringEndsWith('}, true);', rtrim($enviar), 'o enviar() passa doEnvio = true ao gravar()');

        $salvar = substr($js, (int) strpos($js, 'function salvarRascunho(botao)'));
        $salvar = substr($salvar, 0, (int) strpos($salvar, "\n    }\n"));
        self::assertStringNotContainsString(', true)', $salvar, 'o "Salvar rascunho" grava sem envio=1');
    }
}
