<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria I-xss 5.03: o currículo ("Baixar PDF" sem pdfUrl) e o termo ("Imprimir") eram escritos por
 * document.write numa janela about:blank, que herda a origem do BlueJus e não leva a CSP da resposta do fetch.
 * Agora imprimem num iframe oculto `sandbox` SEM `allow-scripts` (allow-same-origin para chamar o print() do
 * quadro, allow-modals para o navegador não ignorar o print()), preenchido por srcdoc: nada do HTML roda.
 *
 * Regex não executa JS: a impressão de verdade é smoke do dono.
 */
#[CoversNothing]
final class ImpressaoDoTrabalhoExternoArquiteturaTest extends TestCase
{
    private const JS = __DIR__ . '/../../public/js/trabalho-externo.js';

    private static function funcao(string $js, string $nome): string
    {
        $ok = preg_match('/^    function ' . $nome . '\(.*?(?=^    (?:\/\*\*|function |var |\/\/)|\z)/ms', $js, $m);
        self::assertSame(1, $ok, "função {$nome} não encontrada");

        return $m[0];
    }

    #[TestDox('Nenhum document.write nem janela about:blank no Trabalho Externo; nenhum iframe com allow-scripts')]
    public function testSemDocumentWrite(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringNotContainsString('document.write', $js);
        self::assertDoesNotMatchRegularExpression("/window\\.open\\(\\s*''/", $js);
        self::assertStringNotContainsString('allow-scripts', $js);
        self::assertStringNotContainsString('allow-popups', $js);
        self::assertStringNotContainsString('allow-top-navigation', $js);
    }

    #[TestDox('Currículo e termo imprimem pelo iframe sandbox: o sandbox entra antes do srcdoc e o srcdoc antes de o quadro entrar na página')]
    public function testImprimePorIframeSandbox(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("var SANDBOX_IMPRESSAO = 'allow-same-origin allow-modals';", $js);
        self::assertStringContainsString('imprimirEmQuadro(X.html)', self::funcao($js, 'baixarPdfDoCv'));
        self::assertStringContainsString('imprimirEmQuadro(r.html)', self::funcao($js, 'imprimirTermo'));

        $quadro = self::funcao($js, 'imprimirEmQuadro');
        $sandbox = strpos($quadro, "quadro.setAttribute('sandbox', SANDBOX_IMPRESSAO);");
        $srcdoc = strpos($quadro, 'quadro.srcdoc = html;');
        $anexa = strpos($quadro, 'document.body.appendChild(quadro);');
        self::assertNotFalse($sandbox);
        self::assertNotFalse($srcdoc);
        self::assertNotFalse($anexa);
        self::assertLessThan($srcdoc, $sandbox, 'o sandbox é posto ANTES de o documento existir');
        self::assertLessThan($anexa, $srcdoc, 'e antes de o iframe entrar na página');
        self::assertStringContainsString('quadro.contentWindow', $quadro);
        self::assertStringContainsString('.print()', $quadro);
    }
}
