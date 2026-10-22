<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `meta-detalhe.js` e `esteira-da-pasta.js` lidos como folha, no padrão de `MetaDespachoJsTest`: as janelas novas
 * (04B L106 e 04 dc L133) prendem o Tab, travam a rolagem e devolvem o foco a quem as abriu; na página da meta, só o
 * aviso de sucesso vira o aviso do desenho (L176) e some em 3 segundos.
 */
#[Group('tarefa')]
final class MetaJanelasEAvisosJsTest extends TestCase
{
    private const DETALHE = __DIR__ . '/../../../public/js/meta-detalhe.js';
    private const ESTEIRA = __DIR__ . '/../../../public/js/esteira-da-pasta.js';

    private static function js(string $arquivo): string
    {
        return (string) file_get_contents($arquivo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function janelas(): iterable
    {
        yield 'página da meta' => [self::DETALHE, 'origemDaJanela'];
        yield 'Configurar caixa' => [self::ESTEIRA, 'origem'];
    }

    #[DataProvider('janelas')]
    #[TestDox('Janela de $arquivo: o Tab e o Shift+Tab dão a volta dentro dela')]
    public function testPrendeOTab(string $arquivo, string $origem): void
    {
        $js = self::js($arquivo);
        self::assertMatchesRegularExpression("/if \\(e\\.key === 'Tab'[^)]*\\) \\{?\\s*prenderTab\\(e\\);/", $js);
        self::assertMatchesRegularExpression('/if \(e\.shiftKey && \(!dentro \|\| document\.activeElement === primeiro\)\) \{\s*e\.preventDefault\(\);\s*ultimo\.focus\(\);/', $js);
        self::assertMatchesRegularExpression('/else if \(!e\.shiftKey && \(!dentro \|\| document\.activeElement === ultimo\)\) \{\s*e\.preventDefault\(\);\s*primeiro\.focus\(\);/', $js);
    }

    #[DataProvider('janelas')]
    #[TestDox('Janela de $arquivo: trava a rolagem aberta e, ao fechar, solta e devolve o foco a quem a abriu')]
    public function testRolagemEFoco(string $arquivo, string $origem): void
    {
        $js = self::js($arquivo);
        self::assertStringContainsString("el.style.overflow = travar ? 'hidden' : '';", $js);
        self::assertMatchesRegularExpression('/' . $origem . ' = (origem|quemAbriu) \|\| document\.activeElement;[\s\S]*?hidden = false;[\s\S]*?travarRolagem\(true\);/', $js);
        self::assertMatchesRegularExpression('/hidden = true;[\s\S]*?travarRolagem\(false\);\s*if \(' . $origem . ' && typeof ' . $origem . '\.focus === \'function\'\) \{? ?' . $origem . '\.focus\(\);/', $js);
    }

    #[TestDox('Configurar caixa: o ⋮, o clique direito e o duplo clique passam quem abriu (o ⋮ ou o link da caixa)')]
    public function testEsteiraPassaQuemAbriu(): void
    {
        $js = self::js(self::ESTEIRA);
        self::assertStringContainsString('abrir(caixa, menu);', $js);
        self::assertSame(2, substr_count($js, 'abrir(caixa, menu || link);'));
        self::assertSame(0, substr_count($js, 'abrir(caixa);'));
    }

    #[TestDox('Página da meta: só o aviso de sucesso leva .is-toast e o timer de 3 segundos; o de erro fica até fechar')]
    public function testSoOSucessoViraToast(): void
    {
        self::assertMatchesRegularExpression(
            "/var sucesso = aviso\\.classList\\.contains\\('alert-success'\\);[\\s\\S]*?if \\(!sucesso\\) return;\\s*aviso\\.classList\\.add\\('is-toast'\\);\\s*setTimeout\\(function \\(\\) \\{ aviso\\.remove\\(\\); \\}, 3000\\);/",
            self::js(self::DETALHE),
        );
        self::assertSame(1, substr_count(self::js(self::DETALHE), 'setTimeout('), 'nenhum outro timer tira aviso');
    }
}
