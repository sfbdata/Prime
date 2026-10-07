<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O menu do botão direito no registro do Relatório financeiro (FPN-006), no script do fim de
 * `templates/pasta/_financeiro.html.twig`, lido como FOLHA: sem navegador no PHPUnit, o que se
 * prova é o contrato com a marcação e que Editar/Excluir reaproveitam os botões da janela de
 * 15 min — sem caminho próprio até o servidor.
 */
#[Group('pasta')]
final class PastaFinanceiroRegistroMenuJsTest extends TestCase
{
    private const TWIG       = __DIR__ . '/../../../templates/pasta/_financeiro.html.twig';
    private const OBS_SCRIPT = __DIR__ . '/../../../templates/pasta/_financeiro_obs_script.html.twig';

    private function fonte(): string
    {
        return (string) file_get_contents(self::TWIG);
    }

    private function trecho(string $de, string $ate): string
    {
        $fonte  = $this->fonte();
        $inicio = strpos($fonte, $de);
        self::assertNotFalse($inicio, "trecho começa em: {$de}");
        $fim = strpos($fonte, $ate, $inicio);
        self::assertNotFalse($fim, "trecho termina em: {$ate}");

        return substr($fonte, $inicio, $fim - $inicio);
    }

    #[TestDox('o botão direito no registro abre o #financeiroObsMenu no ponto do clique, deixando links, botões e o editor com o navegador')]
    public function testAbreNoRegistro(): void
    {
        $ramo = $this->trecho("const registro = e.target.closest('#financeiroObsLista .ps-anotacao');", 'const linhaPag');

        self::assertStringContainsString("getElementById('financeiroObsMenu')", $ramo);
        self::assertStringContainsString("e.target.closest('a, button, .ps-anotacao-edicao')", $ramo);
        self::assertStringContainsString('e.preventDefault()', $ramo);
        self::assertStringContainsString('abrir(menuObs, registro, e.clientX, e.clientY)', $ramo);
    }

    #[TestDox('Editar e Excluir só aparecem quando o cartão traz .btn-editar-obs e .btn-excluir-obs')]
    public function testEditarExcluirSoComOsBotoes(): void
    {
        $prep = $this->trecho('function prepararMenuObs(', 'function menuDoGatilho(');

        self::assertStringContainsString("registro.querySelector('.btn-editar-obs')", $prep);
        self::assertStringContainsString("registro.querySelector('.btn-excluir-obs')", $prep);
        self::assertStringContainsString("querySelectorAll('.js-fin-obs-so-autor')", $prep);
        self::assertStringContainsString('el.hidden = !podeGerenciar', $prep);
        self::assertStringContainsString("'.ps-anotacao-janela'", $prep);
        self::assertStringNotContainsString('innerHTML', $prep);
    }

    #[TestDox('os itens clicam os botões do próprio cartão e copiam o texto do .ql-editor; nada de fetch novo')]
    public function testReusaOsBotoesDoCartao(): void
    {
        $ramo = $this->trecho("if (item.classList.contains('js-fin-obs-copiar'))", "if (item.classList.contains('js-fin-doc-ver-documentos'))");

        self::assertStringContainsString("'.obs-conteudo .ql-editor'", $ramo);
        self::assertStringContainsString("'.btn-editar-obs' : '.btn-excluir-obs'", $ramo);
        self::assertStringContainsString('botao.click()', $ramo);
        self::assertStringNotContainsString('fetch(', $ramo);

        // E os botões continuam atendidos pela delegação da lista no script das observações.
        $obs = (string) file_get_contents(self::OBS_SCRIPT);
        self::assertStringContainsString("obsLista.addEventListener('click'", $obs);
        self::assertStringContainsString("e.target.closest('.btn-editar-obs')", $obs);
        self::assertStringContainsString("e.target.closest('.btn-excluir-obs')", $obs);
    }
}
