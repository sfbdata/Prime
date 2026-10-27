<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pacote do Claude Designer de 13/10 (`01 - Dashboard 1.2.2.dc.html`): no painel BlueJus
 * Intelligence as pílulas (999px) viraram cantos de 4px, o padrão PJe. A troca é seletiva — o
 * mesmo arquivo mantém 999px nos preenchimentos das barras (qualBarra, barA/barV), no trilho do
 * interruptor (sw()) e na barra de 10px do ritmo. O teste trava as duas metades: o que mudou e o
 * que o desenho manteve, para ninguém "padronizar" tudo de uma vez. O desenho VS 25.10.26 tirou as
 * pílulas que sobravam: preenchimento da qualidade, trilho do interruptor e barra do ritmo viraram 3px.
 *
 * Que o canto aparece quadrado na tela é smoke; aqui só se prova que a regra está no seletor certo.
 */
final class DashboardCantosPjeIntelligenceCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/dashboard-inteligencia.css';

    /** Valor de `border-radius` da regra cujo seletor é exatamente `$seletor`. */
    private function raio(string $seletor): string
    {
        $css = (string) file_get_contents(self::CSS);
        $ok  = preg_match('/^' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', $css, $m);
        self::assertSame(1, $ok, 'Falta a regra ' . $seletor);
        $achou = preg_match('/border-radius:\s*([^;]+);/', $m[1], $r);
        self::assertSame(1, $achou, 'A regra ' . $seletor . ' não declara border-radius');

        return trim($r[1]);
    }

    /** @return iterable<string, array{string}> seletor do BlueJus ← linha do dc 13/10 */
    public static function quatroPixels(): iterable
    {
        yield 'interruptor Modo avançado (dc L874)' => ['.db-page .db-ia-av-switch'];
        yield 'trilho da qualidade (dc L997)' => ['.db-page .db-ia-qual-trilho'];
        yield 'trilho da distribuição (dc L1077)' => ['.db-page .db-ia-dist-trilho'];
        yield 'contagem por nível (dc L2618)' => ['.db-page .db-ia-chip'];
        yield 'nível do alerta (dc L2626)' => ['.db-page .db-ia-nivel-chip'];
        yield 'perguntas sugeridas (dc L2642)' => ['.db-page .db-ia-pilula'];
    }

    #[DataProvider('quatroPixels')]
    #[TestDox('cantos de 4px como no dc de 13/10: $seletor')]
    public function testCantoDeQuatroPixels(string $seletor): void
    {
        self::assertSame('4px', $this->raio($seletor));
    }

    /** @return iterable<string, array{string}> */
    public static function continuaPilula(): iterable
    {
        yield 'preenchimento da qualidade (qualBarra, dc VS 25.10.26 L2536)' => ['.db-page .db-ia-qual-barra'];
        yield 'trilho do interruptor (sw(), dc VS 25.10.26 L2496)' => ['.db-page .db-ia-switch'];
        yield 'barra do ritmo, 10px (dc VS 25.10.26 L1241)' => ['.db-page .db-ia-barra'];
    }

    #[DataProvider('continuaPilula')]
    #[TestDox('a pílula que sobrava virou 3px no dc VS 25.10.26: $seletor')]
    public function testPilulaQueSobravaVirouTresPixels(string $seletor): void
    {
        self::assertSame('3px', $this->raio($seletor));
    }
}
