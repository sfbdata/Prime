<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A chave "Escritório" da aba Configurações do Ponto (spec docs/specs/ponto-desenho-0811.md, E7): se o Master desligou
 * o Global depois que a aba carregou, o servidor recusa com 422 e `global: false`, e a tela trava a chave na hora (o
 * resto das recusas continua virando a frase da linha). PHPUnit não roda o JS; a prova é a fonte, como nos outros
 * testes de JS do Ponto. A resposta do servidor é provada em LigaEDesligaDoPontoTest.
 */
#[CoversNothing]
#[Group('ponto')]
final class ChaveDoEscritorioTravaNaRecusaJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/ponto-configuracoes.js';

    #[TestDox('A recusa leva o corpo da resposta adiante; com `global: false`, trava a chave do escritório (e o Global do Master) em vez de só mostrar a frase')]
    public function testRecusaPeloGlobalTravaAChave(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString(
            "return { ok: false, erro: dados && typeof dados.erro === 'string' ? dados.erro : TEXTO_FALHA, dados: dados };",
            $js,
            'o corpo da recusa chega a quem chamou',
        );
        self::assertMatchesRegularExpression(
            '/if \(resultado\.ok === false && resultado\.dados && resultado\.dados\.global === false\) \{\s*travarPeloGlobal\(linha\);\s*return;\s*\}\s*if \(!resultado\.ok\) \{\s*mostrarErro\(linha, resultado\.erro\);/',
            $js,
            'a trava vem antes da frase genérica da recusa',
        );
        self::assertMatchesRegularExpression(
            "/function travarPeloGlobal\\(linha\\) \\{\\s*const global = linha\\.querySelector\\('\\[data-config-global\\]'\\);\\s*if \\(global\\) \\{\\s*global\\.setAttribute\\('aria-checked', 'false'\\);\\s*\\}\\s*desenharEscritorio\\(linha, false\\);\\s*\\}/",
            $js,
        );
    }

    #[TestDox('O aviso do Global ("liberado/desativado para todos os escritórios") sai no aviso flutuante do Ponto (E8, L266), não no do sistema; o do sistema só de reserva')]
    public function testAvisoDoGlobalNoFlutuanteDoPonto(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertMatchesRegularExpression(
            "/if \\(window\\.PontoAvisoFlutuante\\) \\{\\s*window\\.PontoAvisoFlutuante\\.mostrar\\(aviso, 'ok'\\);\\s*\\} else if \\(window\\.bjAviso\\) \\{\\s*window\\.bjAviso\\(aviso\\);\\s*\\}/",
            $js,
            'o aviso do Global voltou para o componente do sistema',
        );
        self::assertSame(1, substr_count($js, 'window.bjAviso('), 'o aviso do sistema só na reserva');
    }
}
