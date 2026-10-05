<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A6 da Trilha A (padrão PJe, cantos de até 4px): o gerenciador de arquivos da
 * aba Documentos usa o `pasta-arquivos.css`, que é COMPARTILHADO com a tela do
 * Objeto (cobrança) — tela que está fora desta frente e deve continuar como está.
 * Por isso os raios do gerenciador só podem mudar sob `.ps-page`.
 *
 * Confere a FOLHA, não a tela: se alguém "simplificar" trocando o 14px no próprio
 * pasta-arquivos.css, o Objeto muda de cara sem ninguém pedir — e nenhum teste
 * de HTML veria.
 */
final class PastaShowRaiosDoGerenciadorTest extends TestCase
{
    private const PASTA_SHOW_CSS     = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_ARQUIVOS_CSS = __DIR__ . '/../../../public/css/pasta-arquivos.css';

    #[TestDox('o raio de 4px do gerenciador é sobrescrito SÓ sob .ps-page, no pasta-show.css')]
    public function testSobrescritaEscopadaNaPasta(): void
    {
        $css = (string) file_get_contents(self::PASTA_SHOW_CSS);

        self::assertMatchesRegularExpression(
            '/\.ps-page \.fm \{[^}]*--fm-radius: 4px;[^}]*--fm-radius-sm: 3px;/',
            $css,
            'a pasta redefine os tokens do gerenciador no seu próprio escopo'
        );
    }

    #[TestDox('o pasta-arquivos.css compartilhado continua com os raios originais (14px/9px) para o Objeto')]
    public function testArquivoCompartilhadoIntacto(): void
    {
        $css = (string) file_get_contents(self::PASTA_ARQUIVOS_CSS);

        self::assertMatchesRegularExpression('/\.fm \{[^}]*--fm-radius: 14px;/', $css);
        self::assertMatchesRegularExpression('/\.fm \{[^}]*--fm-radius-sm: 9px;/', $css);
    }
}
