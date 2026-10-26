<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Desenho 10 VS 25.10.26: o prefixo "BJ-" do cupom é opcional na conferência ao vivo e ela começa em 6 caracteres do
 * código. O comportamento no navegador é do smoke do dono; aqui se trava a regra escrita no JS.
 */
final class EntradaCupomAoVivoJsTest extends TestCase
{
    #[TestDox('a conferência ao vivo aceita o código sem "BJ-" e só começa em 6 caracteres')]
    public function testPrefixoOpcionalEMinimoDeSeis(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/auth/entrada.js');

        self::assertStringContainsString("/^[A-Z0-9]{6,12}\$/.test(valor.replace(/^BJ-/, ''))", $js);
        self::assertStringNotContainsString('/^BJ-[A-Z0-9]{4,12}$/', $js, 'o prefixo não pode ser obrigatório');
    }
}
