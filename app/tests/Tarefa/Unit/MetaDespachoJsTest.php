<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `meta-despacho.js` lido como folha, no padrão de `CentralJsTest` (spec `minhas-metas-esteira-2210.md`, L7;
 * desenho 04B L144-145): Ctrl+Enter e Cmd+Enter despacham, e o Despachar sai só com texto ou só com anexo.
 */
#[Group('tarefa')]
final class MetaDespachoJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/meta-despacho.js';

    #[TestDox('Ctrl+Enter e Cmd+Enter (metaKey, no Mac) no campo despacham, sem quebrar a linha')]
    public function testCtrlOuCmdEnterDespacha(): void
    {
        self::assertMatchesRegularExpression(
            "/campo\\.addEventListener\\('keydown', function \\(e\\) \\{\\s*if \\(e\\.key === 'Enter' && \\(e\\.ctrlKey \\|\\| e\\.metaKey\\)\\) \\{\\s*e\\.preventDefault\\(\\);\\s*enviar\\(''\\);/",
            $this->js(),
        );
    }

    #[TestDox('Despachar sem texto e sem anexo não envia; só com anexo envia (o anexo conta como conteúdo)')]
    public function testDespacharSoComAnexo(): void
    {
        $js = $this->js();
        self::assertMatchesRegularExpression(
            "/function enviar\\(valor\\) \\{[\\s\\S]*?var temAnexo = !!\\(anexo && anexo\\.files && anexo\\.files\\.length\\);\\s*if \\(valor === '' && campo\\.value\\.trim\\(\\) === '' && !temAnexo\\) return;\\s*acao\\.value = valor;\\s*form\\.submit\\(\\);/",
            $js,
        );
        self::assertStringContainsString("var anexo = form.querySelector('#despachoAnexo');", $js);
    }

    #[TestDox('Os botões de ação (Enviar para revisão, Devolver) não dependem de texto: o servidor decide')]
    public function testAcoesNaoExigemTexto(): void
    {
        self::assertMatchesRegularExpression(
            "/botao\\.addEventListener\\('click', function \\(\\) \\{ enviar\\(botao\\.dataset\\.acao \\|\\| ''\\); \\}\\);/",
            $this->js(),
        );
    }

    private function js(): string
    {
        $js = (string) file_get_contents(self::JS);
        self::assertNotSame('', $js);

        return $js;
    }
}
