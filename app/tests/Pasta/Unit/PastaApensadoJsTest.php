<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-apensado.js` (P4f) lido como FOLHA: não há navegador no PHPUnit, então o que se prova é
 * que ele não monta HTML e que os ids, classes e atributos que ele lê são os que os templates entregam.
 */
#[Group('pasta')]
final class PastaApensadoJsTest extends TestCase
{
    private const JS        = __DIR__ . '/../../../public/js/pasta-apensado.js';
    private const SHOW      = __DIR__ . '/../../../templates/pasta/show.html.twig';
    private const PROCESSOS = __DIR__ . '/../../../templates/pasta/_processos_vinculados.html.twig';
    private const DADOS     = __DIR__ . '/../../../templates/pasta/_dados_trilho_topo.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('Não monta HTML: sem innerHTML, outerHTML, insertAdjacentHTML nem document.write')]
    public function testSemInnerHtml(): void
    {
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $this->js(), "{$proibido} não pode aparecer");
        }
        self::assertStringContainsString('textContent', $this->js());
    }

    #[TestDox('Modo apenso do modal: os ids que o JS lê existem no show.html.twig, e o botão do cartão leva data-vincular-modo="apenso"')]
    public function testContratoDoModal(): void
    {
        $js   = $this->js();
        $show = (string) file_get_contents(self::SHOW);
        foreach (['modalVincularProcesso', 'mp-modo', 'mp-apenso-tipo', 'mp-tipoApenso', 'mp-titulo-texto', 'mp-salvar-texto'] as $id) {
            self::assertStringContainsString("getElementById('{$id}')", $js);
            self::assertStringContainsString('id="' . $id . '"', $show, "#{$id} é contrato com o JS");
        }
        self::assertStringContainsString("'show.bs.modal'", $js);
        self::assertStringContainsString("getAttribute('data-vincular-modo') === 'apenso'", $js);
        self::assertStringContainsString('data-vincular-modo="apenso"', (string) file_get_contents(self::PROCESSOS));
        // O envio continua o do show: ele avisa o sucesso por este evento.
        self::assertStringContainsString("'mp:processo-vinculado'", $js);
        self::assertStringContainsString("new CustomEvent('mp:processo-vinculado')", $show);
    }

    #[TestDox('Desvincular apensado: a classe do form e o data-confirmar-texto são os do cartão; troca o cartão pelo HTML do servidor')]
    public function testContratoDoDesvincular(): void
    {
        $js        = $this->js();
        $processos = (string) file_get_contents(self::PROCESSOS);

        self::assertStringContainsString("form.matches('form.js-desvincular-apensado')", $js);
        self::assertStringContainsString('js-desvincular-apensado', $processos);
        self::assertStringContainsString("getAttribute('data-confirmar-texto')", $js);
        self::assertStringContainsString('data-confirmar-texto=', $processos);
        self::assertStringContainsString("titulo: 'Desvincular apensado'", $js);
        self::assertStringContainsString("rotulo: 'Desvincular'", $js);
        self::assertStringContainsString('window.mpSwapProcessos(dados.html)', $js);
    }

    #[TestDox('Dica da pílula da aba Dados: classe e data-dica-* existem no template')]
    public function testContratoDaPilula(): void
    {
        $js    = $this->js();
        $dados = (string) file_get_contents(self::DADOS);

        self::assertStringContainsString("closest('.ps-chip-vinc--apensados')", $js);
        foreach (['ps-chip-vinc--apensados', 'data-dica-aberto=', 'data-dica-fechado='] as $marca) {
            self::assertStringContainsString($marca, $dados);
        }
    }
}
