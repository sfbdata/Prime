<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Filtros "Pagantes" e por plano de "Contas e escritórios" (pedido do Farlei de 27/11), lidos como FOLHA no padrão de
 * PainelRotuloDoConvenioJsTest: sem navegador no PHPUnit, prova-se o contrato do public/js/master/painel.js.
 */
#[CoversNothing]
final class PainelPagantesEPlanoJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/master/painel.js');
    }

    #[TestDox('"Pagantes" é opção do select de situação e mostra só quem tem `pagante`, fora da quarentena')]
    public function testFiltroPagantes(): void
    {
        $js = self::js();

        self::assertStringContainsString("'<option value=\"' + PAGANTES + '\"'", $js);
        self::assertStringContainsString('S.fSit === PAGANTES ? !!c.pagante && c.situacao !== QUARENTENA', $js);
        self::assertStringContainsString("'master.js.contas.nenhuma_pagante'", $js);
    }

    #[TestDox('a linha da conta pagante leva a etiqueta com plano, valor e data; em carência, o texto próprio')]
    public function testEtiquetaDoPagante(): void
    {
        $js = self::js();
        $inicio = strpos($js, 'function rotuloDoPagante(c) {');
        self::assertNotFalse($inicio);
        $funcao = substr($js, $inicio, 700);

        self::assertStringContainsString("if (!p) return '';", $funcao);
        self::assertStringContainsString("'master.js.contas.pagante_carencia'", $funcao);
        self::assertStringContainsString("'master.js.contas.pagante'", $funcao);
        self::assertStringContainsString('rotuloDoConvenio(c) + rotuloDoPagante(c)', $js);
    }

    #[TestDox('filtro por plano pelo `espaco.planoCodigo`, lido do endereço (&plano=) e escrito de volta nele')]
    public function testFiltroPorPlano(): void
    {
        $js = self::js();

        self::assertStringContainsString('(!S.fPlano || c.espaco.planoCodigo === S.fPlano)', $js);
        self::assertStringContainsString("const plano = sec === 'contas' ? doHash('plano') || '' : undefined", $js);
        self::assertStringContainsString("S.sec === 'contas' && S.fPlano ? '&plano=' + S.fPlano : ''", $js);
        self::assertStringContainsString("t.matches('[data-f-plano]')", $js);
    }
}
