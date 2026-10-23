<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O script "Mover para outra carteira" (fim de `templates/pasta/_cabecalho.html.twig`) lido como FOLHA: não há
 * harness de navegador no PHPUnit, então o que se prova aqui é o contrato com o template (`#psMoverCarteira`,
 * `data-url`, `data-csrf`, `data-nup`, `.js-carteira-opt` com `data-carteira`/`data-rotulo`/`data-rotulo-atual`) e
 * com o endpoint (FormData com `_token` e `carteira`, `{sucesso, carteira, rotulo}` / `{erro}`). O clique em si é do
 * smoke do dono.
 */
final class PastaMoverCarteiraJsTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    private function bloco(): string
    {
        $twig   = (string) file_get_contents(self::TEMPLATE);
        $inicio = strpos($twig, "var painel = document.getElementById('psMoverCarteira');");
        self::assertNotFalse($inicio, 'o bloco do submenu de carteiras existe');
        $fim = strpos($twig, '})();', $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    #[TestDox('a opção atual não envia nada (no desenho, o clique na atual não faz nada), nem um segundo clique durante o envio')]
    public function testAtualNaoEnvia(): void
    {
        self::assertStringContainsString(
            "if (!opt || opt.getAttribute('aria-checked') === 'true' || painel.dataset.enviando === '1') { return; }",
            $this->bloco(),
        );
    }

    #[TestDox('envia por XHR um FormData com o _token do painel e a carteira da opção, para a data-url do painel')]
    public function testContratoComOEndpoint(): void
    {
        $js = $this->bloco();
        self::assertStringContainsString("corpo.append('_token', painel.dataset.csrf);", $js);
        self::assertStringContainsString("corpo.append('carteira', opt.dataset.carteira);", $js);
        self::assertStringContainsString('fetch(painel.dataset.url, {', $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
    }

    #[TestDox('com sucesso, o "· atual" e o bi-check2 passam para a carteira da RESPOSTA, sem recarregar, e sai o aviso do desenho')]
    public function testSucessoMarcaAtualEAvisa(): void
    {
        $js = $this->bloco();
        self::assertStringContainsString('marcarAtual(r.dados.carteira);', $js);
        self::assertStringContainsString("opt.querySelector('i').className = 'bi ' + (eAtual ? 'bi-check2' : 'bi-briefcase');", $js);
        self::assertStringContainsString('opt.dataset.rotuloAtual : opt.dataset.rotulo', $js);
        self::assertStringContainsString(
            "BJ_T.t('pasta_cabecalho.js.cabecalho.carteira_movida', { nup: painel.dataset.nup, carteira: r.dados.rotulo })",
            $js,
        );
        self::assertStringNotContainsString('location.reload', $js);
        self::assertLessThan(strpos($js, 'marcarAtual(r.dados.carteira)'), strpos($js, '!r.ok || !r.dados.sucesso'), 'erro é tratado antes de marcar');
    }

    #[TestDox('falha mostra o erro do servidor (ou o do catálogo) no aviso da tela e libera o próximo clique')]
    public function testFalha(): void
    {
        $js = $this->bloco();
        self::assertStringContainsString("BJ_T.t('pasta_cabecalho.js.cabecalho.carteira_erro')", $js);
        self::assertStringContainsString("BJ_T.t('pasta_cabecalho.js.cabecalho.erro_comunicacao')", $js);
        self::assertStringContainsString("window.avisarPs(mensagem, 'erro');", $js);
        self::assertStringContainsString("painel.dataset.enviando = '';", $js);
    }
}
