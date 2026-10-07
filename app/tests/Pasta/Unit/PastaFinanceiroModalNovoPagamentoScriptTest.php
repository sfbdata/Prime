<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Smoke (Chromium e Firefox, 06/10): o modal "Adicionar pagamento" abria morto — prévia sem
 * cálculo, 1º vencimento vazio, botão desabilitado. O script inline da aba Financeiro roda
 * dentro de `.ps-page`, ANTES de o `#modalNovoPagamento` existir (ele vive no fim do
 * show.html.twig, fora de `.ps-page`), e saía em `if (!modalEl || !form) return;`.
 *
 * Teste de FOLHA: trava a ordem do documento que causa o defeito e a inicialização que o
 * tolera (DOMContentLoaded se o documento ainda carrega, senão na hora).
 */
final class PastaFinanceiroModalNovoPagamentoScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../templates/pasta/_financeiro_pagamentos_script.html.twig';
    private const SHOW   = __DIR__ . '/../../../templates/pasta/show.html.twig';

    #[TestDox('o modal fica depois de .ps-page no show — por isso o script não pode buscá-lo na hora em que roda')]
    public function testModalVemDepoisDoScript(): void
    {
        $show = (string) file_get_contents(self::SHOW);
        $fimPage = strpos($show, '</div>{# /.ps-page #}');
        $modal   = strpos($show, 'id="modalNovoPagamento"');

        self::assertNotFalse($fimPage);
        self::assertNotFalse($modal);
        self::assertGreaterThan($fimPage, $modal, 'se o modal voltou para dentro de .ps-page, revise este teste e o comentário do script');
    }

    #[TestDox('o modal só é procurado dentro de iniciarModalNovoPagamento, chamada no DOMContentLoaded ou já, se o DOM está pronto')]
    public function testInicializacaoToleraAOrdemDoDom(): void
    {
        $js = (string) file_get_contents(self::SCRIPT);

        $funcao = strpos($js, '    function iniciarModalNovoPagamento() {');
        self::assertNotFalse($funcao);
        // Todas as buscas dos elementos do modal moram DENTRO da função.
        foreach (['modalNovoPagamento', 'formNovoPagamento', 'btnSalvarPagamento'] as $id) {
            $pos = strpos($js, "document.getElementById('" . $id . "')");
            self::assertNotFalse($pos, $id);
            self::assertGreaterThan($funcao, $pos, $id . ' é buscado antes de o DOM estar pronto');
        }
        self::assertMatchesRegularExpression(
            "/if \(document\.readyState === 'loading'\) \{\s*document\.addEventListener\('DOMContentLoaded', iniciarModalNovoPagamento\);\s*\} else \{\s*iniciarModalNovoPagamento\(\);\s*\}\s*\}\)\(\);/",
            $js
        );
    }
}
