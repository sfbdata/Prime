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

    #[TestDox('FPN-006b: sem .btn-editar-obs/.btn-excluir-obs no cartão, Editar e Excluir ficam À VISTA, desabilitados, "· bloqueado" com cadeado (dc L.5958)')]
    public function testEditarExcluirBloqueadosSemOsBotoes(): void
    {
        $prep = $this->trecho('function prepararMenuObs(', 'function menuDoGatilho(');

        self::assertStringContainsString("registro.querySelector('.btn-editar-obs')", $prep);
        self::assertStringContainsString("registro.querySelector('.btn-excluir-obs')", $prep);
        self::assertStringNotContainsString('.hidden', $prep, 'bloqueado não é escondido');
        self::assertStringContainsString("pintarItemObs(editar, false, 'Editar · bloqueado', 'bi-lock', motivo);", $prep);
        self::assertStringContainsString("pintarItemObs(excluir, false, 'Excluir · bloqueado', 'bi-lock', motivo);", $prep);
        self::assertStringContainsString("pintarItemObs(editar, true, 'Editar' + resto, 'bi-pencil', '');", $prep);
        self::assertStringContainsString("pintarItemObs(excluir, true, 'Excluir comentário' + resto, 'bi-trash3', '');", $prep);
        self::assertStringContainsString("excluir.classList.toggle('ps-fin-menu-item--perigo', podeGerenciar);", $prep, 'bloqueado sem a cor de perigo (x[3] do desenho)');
        self::assertStringContainsString("registro.getAttribute('data-obs-do-autor') === '1' ? OBS_MOTIVO_PRAZO : OBS_MOTIVO_AUTOR", $prep);
        self::assertStringContainsString("'.ps-anotacao-janela'", $prep);
        self::assertStringNotContainsString('innerHTML', $prep);

        $pinta = $this->trecho('function pintarItemObs(', 'function prepararMenuObs(');
        self::assertStringContainsString('item.disabled = !pode;', $pinta);
        self::assertStringContainsString("item.setAttribute('aria-disabled', 'true')", $pinta);
        self::assertStringContainsString('item.title = motivo;', $pinta);

        // Item desabilitado não aciona nada, nem por clique sintético.
        self::assertStringContainsString('!aberto.menu.contains(item) || item.disabled) { return; }', $this->fonte());
        self::assertStringContainsString("data-obs-do-autor=\"{{ obs.autor and app.user and obs.autor.id == app.user.id ? '1' : '0' }}\"", $this->fonte());
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/pasta-show.css');
        self::assertStringContainsString('#financeiro .ps-fin-menu-item:disabled { cursor: default; }', $css);
    }

    #[TestDox('FPN-006b: "Excluir comentário" confirma com o texto exato do desenho (dc comExcluir, L.5903)')]
    public function testConfirmacaoDoDesenho(): void
    {
        $obs = (string) file_get_contents(self::OBS_SCRIPT);

        self::assertStringContainsString("if (!confirm(textoDeConfirmacaoExcluir(btnExcluir.closest('.ps-anotacao')))) return;", $obs);
        self::assertStringNotContainsString('Excluir este registro? Esta ação não pode ser desfeita.', $obs);
        $f = substr($obs, (int) strpos($obs, 'function textoDeConfirmacaoExcluir('), 1200);
        self::assertStringContainsString("'Excluir o comentário de ' + (autor || 'colaborador') + '?", $f);
        self::assertStringContainsString("(quando ? quando + ' · ' : '') + '\"' + txt.slice(0, 140) + (txt.length > 140 ? '…' : '')", $f);
        self::assertStringContainsString("'\". Ele some para toda a equipe e a exclusão fica registrada no histórico.'", $f);
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

    #[TestDox('EDT-003: Imprimir leva à janela de impressão o item, com autor e data do registro, e o PRÓPRIO cartão de onde o menu abriu')]
    public function testImprimirDoRegistro(): void
    {
        $prep = $this->trecho('function prepararMenuObs(', 'function menuDoGatilho(');
        self::assertStringContainsString("const imprimir = menu.querySelector('.js-fin-obs-imprimir');", $prep);
        self::assertStringContainsString("imprimir.setAttribute('data-autor', autor);", $prep);
        self::assertStringContainsString("imprimir.setAttribute('data-quando', quando.replace(' ', ', '));", $prep);
        self::assertStringNotContainsString('data-tipo', $prep, 'o registro financeiro do desenho não tem tipo');
        // Antes do retorno antecipado dos bloqueados: quem não edita também imprime.
        self::assertLessThan(
            (int) strpos($prep, 'if (!podeGerenciar)'),
            (int) strpos($prep, "imprimir.setAttribute('data-autor'"),
        );

        $ramo = $this->trecho("if (item.classList.contains('js-fin-obs-imprimir'))", "// Editar e Excluir do registro clicam");
        self::assertStringContainsString('const cartao = obsAlvo;', $ramo);
        self::assertStringContainsString('window.PastaImpressao.registro(item, cartao)', $ramo);
    }
}
