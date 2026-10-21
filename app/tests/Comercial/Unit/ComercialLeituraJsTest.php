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

    #[TestDox('registra o rastrearLeitura (true só com a chave ligada e habilitada) na raiz da própria Central, não no gancho global')]
    public function testGancho(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("document.querySelectorAll('[data-mc-cp-rastrear]').forEach(ligar);", $js);
        self::assertStringContainsString("raiz.mcExtrasDoEnvio.push(() => ({ rastrearLeitura: !botao.disabled && botao.dataset.mcCpRastrear === '1' }));", $js);
        self::assertStringNotContainsString('window.McCampanha', $js);
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
