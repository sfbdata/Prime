<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O comercial-leitura.js no gancho da F3a e com os textos do dc (L656). */
#[CoversNothing]
final class ComercialLeituraJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/master/comercial-leitura.js';

    #[TestDox('registra no McCampanha.extrasDoEnvio o rastrearLeitura (true só com a chave ligada e habilitada), sem depender da ordem dos scripts')]
    public function testGancho(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('window.McCampanha = window.McCampanha || {};', $js);
        self::assertStringContainsString("(window.McCampanha.extrasDoEnvio = window.McCampanha.extrasDoEnvio || []).push(() => ({ rastrearLeitura: !botao.disabled && botao.dataset.mcCpRastrear === '1' }));", $js);
    }

    #[TestDox('alterna só habilitada, com os textos e os titles do dc')]
    public function testAlternar(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('if (botao.disabled) return;', $js);
        foreach (["'Confirmação de leitura'", "'Sem confirmação de leitura'", "'Rastreando leitura (✓✓). Clique para somente enviar'", "'Clique para rastrear a leitura (✓✓)'"] as $texto) {
            self::assertStringContainsString($texto, $js);
        }
    }
}
