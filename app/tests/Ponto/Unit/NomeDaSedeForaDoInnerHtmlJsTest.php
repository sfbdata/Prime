<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Achado de auditoria interna F2 (spec `docs/specs/seguranca-b-ponto.md` §1.1): o nome da sede é texto digitado por
 * alguém e chega à tela do ponto pelo JSON das sedes. Ele só pode entrar no DOM como texto.
 *
 * PHPUnit não executa JavaScript: o teste lê o script da tela e confere o arranjo. O JSON íntegro é provado por
 * `App\Tests\Ponto\Functional\CercaNaTelaDoPontoTest`.
 */
final class NomeDaSedeForaDoInnerHtmlJsTest extends TestCase
{
    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    /**
     * O valor de cada atribuição a `innerHTML`, do `=` até o `;` que fecha o statement, mesmo que ele ocupe várias
     * linhas (flag `s`). O controle confere que nenhuma atribuição ficou de fora da captura.
     *
     * @return list<string>
     */
    private static function atribuicoesDeInnerHtml(): array
    {
        $tela = self::tela();
        preg_match_all('/\.innerHTML\s*=\s*(.*?);[ \t]*$/ms', $tela, $captura);
        self::assertCount(
            preg_match_all('/\.innerHTML\s*=(?!=)/', $tela),
            $captura[1],
            'alguma atribuição a innerHTML não foi capturada'
        );

        return $captura[1];
    }

    #[TestDox('Nenhum innerHTML da tela do ponto recebe o nome da sede nem outro dado da sede')]
    public function testNenhumInnerHtmlRecebeDadoDaSede(): void
    {
        $atribuicoes = self::atribuicoesDeInnerHtml();

        self::assertNotEmpty($atribuicoes, 'controle: a tela ainda tem innerHTML com texto fixo');
        foreach ($atribuicoes as $valor) {
            self::assertStringNotContainsString('onde.sede', $valor, 'dado da sede em innerHTML: ' . $valor);
            self::assertStringNotContainsString('.nome', $valor, 'nome em innerHTML: ' . $valor);
            self::assertStringNotContainsString('SEDES', $valor);
        }
    }

    #[TestDox('O nome da sede entra por textContent, dentro do strong da mensagem da cerca')]
    public function testNomeDaSedeEntraPorTextContent(): void
    {
        $tela = self::tela();

        self::assertStringContainsString("const nomeDaSede = document.createElement('strong');", $tela);
        self::assertStringContainsString('nomeDaSede.textContent = onde.sede.nome;', $tela);
        self::assertStringContainsString('gpsStatus.replaceChildren(', $tela);
        self::assertSame(1, substr_count($tela, 'onde.sede.nome'), 'o nome da sede só pode aparecer no textContent');
    }

    #[TestDox('Só valores conhecidos entram em innerHTML: texto fixo, rótulo do tipo e número da precisão')]
    public function testInnerHtmlSoComValorConhecido(): void
    {
        $atribuicoes = self::atribuicoesDeInnerHtml();

        $permitidos = ['Math.round(', 'accuracy.toFixed(1)', 'rotuloBotaoBatida()'];
        foreach ($atribuicoes as $valor) {
            preg_match_all('/\$\{(.*?)\}/', $valor, $interpolacoes);
            foreach ($interpolacoes[1] as $expressao) {
                $conhecida = false;
                foreach ($permitidos as $permitido) {
                    $conhecida = $conhecida || str_starts_with(trim($expressao), $permitido);
                }
                self::assertTrue($conhecida, 'interpolação desconhecida em innerHTML: ' . $expressao);
            }
        }
    }
}
