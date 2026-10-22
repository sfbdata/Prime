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
            "/function enviar\\(valor\\) \\{[\\s\\S]*?var temAnexo = !!\\(anexo && anexo\\.files && anexo\\.files\\.length\\);\\s*if \\(valor === '' && campo\\.value\\.trim\\(\\) === '' && !temAnexo\\) return;\\s*if \\(window\\.PastaMencoes\\) campo\\.value = PastaMencoes\\.paraEnvio\\(campo, campo\\.value\\);\\s*acao\\.value = valor;\\s*form\\.submit\\(\\);/",
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

    #[TestDox('@menção (C3): antes de enviar, cada "@Nome" escolhido vira o token que o servidor confere')]
    public function testMencaoViraTokenAoEnviar(): void
    {
        self::assertStringContainsString('if (window.PastaMencoes) campo.value = PastaMencoes.paraEnvio(campo, campo.value);', $this->js());
    }

    #[TestDox('Extrato (L150): abre em janela nova; bloqueada, avisa "Permita pop-ups para gerar o extrato."')]
    public function testExtratoAvisaPopupBloqueado(): void
    {
        $js = $this->js();
        self::assertMatchesRegularExpression(
            "/var janela = window\\.open\\(link\\.href, '_blank'\\);\\s*if \\(!janela\\) \\{\\s*avisar\\(BJ_T\\.t\\('metas\\.detalhe\\.js\\.permita_popups'\\)\\);/",
            $js,
        );
        // 'noopener' nas opções faria o window.open devolver null sempre, e o aviso sairia à toa.
        self::assertStringNotContainsString("window.open(link.href, '_blank', 'noopener')", $js);
        self::assertStringContainsString('janela.opener = null;', $js);
    }

    #[TestDox('O aviso (toast) que volta do servidor some em 3 segundos, como o do desenho (L176)')]
    public function testToastSome(): void
    {
        self::assertMatchesRegularExpression("/\\[data-meta-toast\\][\\s\\S]*?setTimeout\\(function \\(\\) \\{ aviso\\.remove\\(\\); \\}, 3000\\);/", $this->js());
    }

    #[TestDox('pasta-mencoes.js liga o campo simples (data-mencoes-simples) sem editor Quill')]
    public function testCampoSimplesNoMecanismoDaPasta(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/pasta-mencoes.js');
        self::assertStringContainsString("if (!quill && textarea.hasAttribute('data-mencoes-simples')) { quill = campoSimples(textarea); }", $js);
        self::assertStringContainsString("if (textarea.hasAttribute('data-mencoes-simples') || (window.EditorRico && EditorRico.instancia(textarea))) {", $js);
        // Só o que a pessoa digita abre a lista: a troca feita pela própria lista (evento sintético) não.
        self::assertStringContainsString("textarea.addEventListener('input', function (ev) { if (ev.isTrusted) { fn(null, null, 'user'); } });", $js);
    }

    private function js(): string
    {
        $js = (string) file_get_contents(self::JS);
        self::assertNotSame('', $js);

        return $js;
    }
}
