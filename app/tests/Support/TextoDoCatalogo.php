<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Português do catálogo de tradução, por chave completa (ex.: `comum.js.editor.menu.recortar`).
 *
 * Os testes de folha que leem o JS não procuram mais o texto literal (ele saiu do código para o
 * catálogo): travam a CHAMADA com a chave e provam, por aqui, que o português da chave não mudou.
 */
trait TextoDoCatalogo
{
    private static function ptBr(string $chave): string
    {
        $no = CatalogoPtBr::carregar();
        foreach (explode('.', $chave) as $parte) {
            if (!\is_array($no) || !\array_key_exists($parte, $no)) {
                self::fail("chave de catálogo inexistente: {$chave}");
            }
            $no = $no[$parte];
        }
        self::assertIsString($no, "a chave {$chave} não é um texto");

        return $no;
    }
}
