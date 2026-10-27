<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O comercial-leitura.js no gancho da F3a e com os textos do dc (L656). */
#[CoversNothing]
final class ComercialLeituraJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/master/comercial-leitura.js';

    #[TestDox('registra o rastrearLeitura (true só com a chave ligada e habilitada) na raiz da própria Central, não no gancho global, depois do DOM montado (as duas Centrais)')]
    public function testGancho(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("function iniciar() { document.querySelectorAll('[data-mc-cp-rastrear]').forEach(ligar); }", $js);
        self::assertStringContainsString("if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();", $js);
        self::assertStringContainsString("raiz.mcExtrasDoEnvio.push(() => ({ rastrearLeitura: !botao.disabled && botao.dataset.mcCpRastrear === '1' }));", $js);
        self::assertStringNotContainsString('window.McCampanha', $js);
    }

    #[TestDox('alterna só habilitada, com os textos e os titles do dc')]
    public function testAlternar(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('if (botao.disabled) return;', $js);
        self::assertStringContainsString("texto.textContent = ligada ? BJ_T.t('comercial.js.leitura_ligada') : BJ_T.t('comercial.js.leitura_desligada');", $js);
        self::assertStringContainsString("botao.title = ligada ? BJ_T.t('comercial.js.leitura_dica_ligada') : BJ_T.t('comercial.js.leitura_dica_desligada');", $js);
        $catalogo = CatalogoPtBr::carregar()['comercial']['js'];
        self::assertSame(
            ['Confirmação de leitura', 'Sem confirmação de leitura', 'Rastreando leitura (✓✓). Clique para somente enviar', 'Clique para rastrear a leitura (✓✓)'],
            [$catalogo['leitura_ligada'], $catalogo['leitura_desligada'], $catalogo['leitura_dica_ligada'], $catalogo['leitura_dica_desligada']],
        );
    }
}
