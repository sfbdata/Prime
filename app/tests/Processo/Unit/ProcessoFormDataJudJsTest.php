<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Teste de FOLHA do script inline de processo/new.html.twig (Novo/Editar processo), lido como
 * texto, sem navegador: trava o escape do dado que vem do DataJud antes de virar HTML.
 * Um nome de parte com `"><img src=x onerror=alert(1)>` não pode executar script na página.
 */
#[Group('processo')]
final class ProcessoFormDataJudJsTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/processo/new.html.twig';

    private function script(): string
    {
        $twig = file_get_contents(self::TEMPLATE);
        self::assertIsString($twig);
        $ini = strpos($twig, '<script>');
        self::assertNotFalse($ini, 'script inline não encontrado');
        $fim = strpos($twig, '</script>', $ini);
        self::assertNotFalse($fim);

        return substr($twig, $ini, $fim - $ini);
    }

    #[TestDox('esc() escapa os 5 caracteres de HTML (& < > " \') como entidade numérica')]
    public function testEscEscapaOsCincoCaracteres(): void
    {
        $script = $this->script();
        self::assertMatchesRegularExpression(
            '/function esc\(texto\) \{\s*return String\(texto\)\.replace\(\/\[&<>\\\\x22\\\\x27\]\/g, function \(c\) \{ return \'&#\' \+ c\.charCodeAt\(0\) \+ \';\'; \}\);/',
            $script,
        );
    }

    #[TestDox('Toda interpolação ${...} do script passa por esc(), é índice numérico ou ternário de literais')]
    public function testNenhumaInterpolacaoSemEscape(): void
    {
        preg_match_all('/\$\{([^}]*)\}/', $this->script(), $m);
        self::assertNotEmpty($m[1]);

        $seguras = [
            '/^esc\(.*\)$/s',                                   // texto escapado
            '/^(idx|index)$/',                                   // índice numérico do forEach/length
            '/^.+\?\s*\'[a-z]*\'\s*:\s*\'[a-z]*\'$/s',          // ternário que só devolve literal
        ];
        foreach ($m[1] as $expr) {
            $expr = trim($expr);
            $ok = false;
            foreach ($seguras as $padrao) {
                if (1 === preg_match($padrao, $expr)) {
                    $ok = true;
                    break;
                }
            }
            self::assertTrue($ok, "interpolação sem esc() vira HTML: \${{$expr}}");
        }
    }

    #[TestDox('Os campos de parte, movimentação e assunto vindos do DataJud entram no value="" via esc()')]
    public function testCamposDoDataJudPassamPorEsc(): void
    {
        $script = $this->script();
        $campos = [
            "parte.nome || ''", "parte.documento || ''", "parte.papel || ''",
            "mov.dataMovimentacao || ''", "mov.descricao || ''", "mov.tipo || ''", "mov.orgao || ''",
            "assunto.nome || ''", "assunto.codigo ?? ''",
        ];
        foreach ($campos as $campo) {
            self::assertStringContainsString('value="${esc(' . $campo . ')}"', $script);
        }
        // A mensagem de erro do servidor também vai para innerHTML do modal.
        self::assertStringContainsString("showFeedbackModal('error', esc(data.error || ", $script);
        self::assertStringNotContainsString("showFeedbackModal('error', data.error", $script);
    }
}
