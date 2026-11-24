<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Rótulo de convênio das contas no painel do Master (spec docs/specs/convenios.md, PR 2), lido como FOLHA no padrão de
 * PainelPlanosELimitesJsTest: sem navegador no PHPUnit, prova-se o contrato do public/js/master/painel.js.
 */
#[CoversNothing]
final class PainelRotuloDoConvenioJsTest extends TestCase
{
    #[TestDox('sem `convenio` do servidor (chave geral desligada), o painel por estado não traz o rótulo de volta')]
    public function testSemConvenioDoServidorNaoHaRotulo(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/master/painel.js');
        $inicio = strpos($js, 'function rotuloDoConvenio(c) {');
        self::assertNotFalse($inicio);
        $funcao = substr($js, $inicio, 400);

        self::assertStringContainsString('const v = c.uf && c.convenio && (CONVENIO[c.uf] || c.convenio);', $funcao);
        self::assertStringContainsString("if (!v) return '';", $funcao);
    }
}
