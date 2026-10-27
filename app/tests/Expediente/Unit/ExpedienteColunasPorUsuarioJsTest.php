<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Menu "Colunas da tabela" da Lista: o ajuste vem do servidor (`data-colunas`) e é gravado por POST; o JS não lê
 * mais a chave local antiga `expediente_colunas` (sem usuário, compartilhada no navegador). Leitura do texto do
 * arquivo, como os testes de folha da Lista: a prova de comportamento é o functional do controller.
 */
final class ExpedienteColunasPorUsuarioJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/expediente-lista.js');
    }

    #[TestDox('o JS não lê a chave antiga sem usuário e lê o ajuste do servidor em dataset.colunas')]
    public function testLeDoServidorENaoDaChaveAntiga(): void
    {
        $js = self::js();
        self::assertStringNotContainsString("'expediente_colunas'", $js, 'a chave antiga sem usuário não volta');
        self::assertStringContainsString('t.dataset.colunas', $js);
        self::assertStringContainsString("t.getAttribute('data-url-colunas')", $js);
        self::assertStringContainsString("'X-CSRF-Token': t.getAttribute('data-csrf-lista')", $js);
        self::assertStringContainsString('body: JSON.stringify({ colunas: mapa })', $js);
    }

    #[TestDox('migração única: a chave local da própria pessoa vai para o servidor e é apagada; falha de conexão avisa')]
    public function testMigracaoEAviso(): void
    {
        $js = self::js();
        self::assertStringContainsString("var PREFIXO_COLUNAS_LOCAL = 'expediente_colunas:';", $js);
        self::assertStringContainsString("t.getAttribute('data-chave-colunas')", $js);
        self::assertStringContainsString('if (ok) { esquecerAjusteLocal(chave); }', $js);
        self::assertStringContainsString("aviso(BJ_T.t('expediente.js.lista.colunas.falha_gravar'))", $js);
        self::assertStringContainsString('function aplicar() { agendado = 0; pintarCampos(); trazerAjusteLocal(); aplicarColunas(); }', $js);

        $catalogo = CatalogoPtBr::carregar();
        self::assertSame('Falha de conexão ao guardar as colunas', $catalogo['expediente']['js']['lista']['colunas']['falha_gravar']);
    }

    #[TestDox('Os POSTs das colunas vão um por vez, na ordem dos cliques; com ajuste no servidor, a cópia local é apagada')]
    public function testFilaEChaveLocal(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/expediente-lista.js');

        self::assertStringContainsString('filaColunas = filaColunas.then(function () {', $js);
        self::assertStringContainsString('if (colunasSalvas() !== null) { esquecerAjusteLocal(chave); return; }', $js);
    }
}
