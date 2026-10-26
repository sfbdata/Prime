<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tests\Support\CatalogoPtBr;

/**
 * O texto do `pasta-explorador.js`, do `pasta-checklist.js` e do `pasta-metas.js` mora no catálogo, sob
 * `pasta_scripts.js.*` (spec idioma-do-sistema §20.9). Quem antes procurava o literal no JS agora confere as
 * duas pontas: a chamada `BJ_T.t('<chave>'` no arquivo e o texto exato da chave no catálogo em português.
 */
trait TextoDosScriptsDaPasta
{
    /** @var array<string, mixed>|null */
    private static ?array $catalogoDosScriptsDaPasta = null;

    /** O texto em português de `pasta_scripts.js.<area>.<chave>` (ex.: `explorador.mais_acoes`). */
    private static function textoDoScript(string $chave): string
    {
        if (self::$catalogoDosScriptsDaPasta === null) {
            $catalogo                       = CatalogoPtBr::carregar();
            self::$catalogoDosScriptsDaPasta = $catalogo['pasta_scripts']['js'];
        }
        $no = self::$catalogoDosScriptsDaPasta;
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, 'chave pasta_scripts.js.' . $chave);
            self::assertArrayHasKey($parte, $no, 'chave pasta_scripts.js.' . $chave . ' no catálogo');
            $no = $no[$parte];
        }
        self::assertIsString($no);

        return $no;
    }

    /** A chamada da chave no JS e o texto dela no catálogo. */
    private static function assertTextoDoScript(string $esperado, string $chave, string $js): void
    {
        self::assertStringContainsString("BJ_T.t('pasta_scripts.js." . $chave . "'", $js, 'o JS pede a chave ' . $chave);
        self::assertSame($esperado, self::textoDoScript($chave), 'texto da chave ' . $chave);
    }
}
