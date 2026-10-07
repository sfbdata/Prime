<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Vincular cliente sem recarregar (item 6 das pendências da Trilha B), lido como FOLHA: o JS da
 * busca inline (`public/js/pasta-clientes-busca.js`) e o JS de clientes do `pasta/show.html.twig`.
 *
 * Não há harness de navegador no PHPUnit: o que se prova é o contrato — a linha vem do `html` do
 * servidor (não de um espelho JS), a busca a entrega ao show pelo evento `pasta:cliente-vinculado`,
 * o reload fica só como fallback e, em erro, sai mensagem e nada é inserido. O clique em si é
 * smoke do dono.
 */
#[CoversNothing]
final class PastaClientesVincularJsTest extends TestCase
{
    private const BUSCA = __DIR__ . '/../../../public/js/pasta-clientes-busca.js';
    private const SHOW  = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private static function busca(): string
    {
        $js = file_get_contents(self::BUSCA);
        self::assertIsString($js);

        return $js;
    }

    /** O script do modal de clientes do show (do `getElementById('modalAdicionarCliente')` em diante). */
    private static function blocoClientesDoShow(): string
    {
        $twig = file_get_contents(self::SHOW);
        self::assertIsString($twig);
        $inicio = strpos($twig, "const modal = document.getElementById('modalAdicionarCliente');");
        self::assertNotFalse($inicio, 'o bloco de clientes do show existe');
        $fim = strpos($twig, '</script>', $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    /** O `.then(function (r) { ... })` que trata a resposta do vínculo na busca inline. */
    private static function respostaDoVinculo(): string
    {
        $js     = self::busca();
        $inicio = strpos($js, '.then(function (r) {');
        self::assertNotFalse($inicio, 'o tratamento da resposta do vínculo existe');
        $fim = strpos($js, '.catch(', $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('busca inline: entrega o html do servidor ao show pelo evento cancelável pasta:cliente-vinculado')]
    public function testBuscaEntregaOHtmlPeloEvento(): void
    {
        $js = self::busca();

        self::assertStringContainsString("new CustomEvent('pasta:cliente-vinculado'", $js);
        self::assertStringContainsString('cancelable: true', $js);
        self::assertStringContainsString('html: dados.html', $js);
        self::assertStringContainsString('principal: dados.principal', $js);
        self::assertStringContainsString('return !document.dispatchEvent(evento);', $js, 'preventDefault do show é o "recebido"');
        self::assertMatchesRegularExpression("/typeof dados\\.html !== 'string' \\|\\| dados\\.html === ''\\) \\{ return false; \\}/", $js, 'sem html não há o que entregar');
        // A busca não monta a linha: quem a monta é o Twig, quem a posiciona é o show.
        self::assertStringNotContainsString('cliente-linha', $js);
    }

    #[TestDox('busca inline: o reload é só o fallback (ninguém confirmou a linha), não o caminho normal')]
    public function testReloadSoComoFallback(): void
    {
        $resposta = self::respostaDoVinculo();

        self::assertSame(1, substr_count(self::busca(), 'window.location.reload()'), 'um único reload, o do fallback');
        $entrega = strpos($resposta, 'if (!entregarLinha(r.dados)) {');
        $reload  = strpos($resposta, 'window.location.reload()');
        $fechar  = strpos($resposta, 'fechar();');
        self::assertNotFalse($entrega);
        self::assertNotFalse($reload);
        self::assertNotFalse($fechar);
        self::assertLessThan($reload, $entrega, 'o reload mora DENTRO do "não entregou"');
        self::assertLessThan($fechar, $reload, 'linha entregue: só fecha o painel');
    }

    #[TestDox('busca inline: erro do servidor mostra a mensagem e sai ANTES de entregar qualquer linha')]
    public function testErroMostraMensagemENaoInsere(): void
    {
        $resposta = self::respostaDoVinculo();

        $guarda  = strpos($resposta, 'if (!r.ok || !r.dados.sucesso) {');
        $entrega = strpos($resposta, 'entregarLinha(');
        self::assertNotFalse($guarda);
        self::assertNotFalse($entrega);
        self::assertLessThan($entrega, $guarda, 'a guarda de erro vem antes da entrega');

        $ramoErro = substr($resposta, $guarda, $entrega - $guarda);
        self::assertStringContainsString("mostrarErro(r.dados.erro || 'Não foi possível vincular o cliente.');", $ramoErro);
        self::assertStringContainsString('return;', $ramoErro);
        self::assertStringContainsString('vinculando = false;', $ramoErro, 'libera nova tentativa');
    }

    #[TestDox('show: o espelho JS da linha saiu — a linha é o html do servidor, parseado num <template>')]
    public function testShowNaoTemMaisEspelhoDaLinha(): void
    {
        $bloco = self::blocoClientesDoShow();

        self::assertStringNotContainsString('function criarClienteRow', $bloco);
        self::assertStringNotContainsString('function mascararDocumento', $bloco);
        self::assertStringContainsString("document.createElement('template')", $bloco);
        self::assertStringContainsString("linha.classList.contains('cliente-linha') ? linha : null", $bloco, 'html que não é uma linha não entra');
        self::assertStringContainsString('function appendClienteRow(html, principal, total)', $bloco);
    }

    #[TestDox('show: modal (cadastrar e vincular) e busca inline usam o MESMO appendClienteRow com o html')]
    public function testUmCaminhoSoParaInserir(): void
    {
        $bloco = self::blocoClientesDoShow();

        self::assertSame(2, substr_count($bloco, 'dados.sucesso && appendClienteRow(dados.html, dados.principal, dados.total)'), 'cadastrar e vincular do modal');
        self::assertStringContainsString("document.addEventListener('pasta:cliente-vinculado'", $bloco);
        self::assertMatchesRegularExpression('/if \(appendClienteRow\(dados\.html, dados\.principal, dados\.total\)\) \{\s*event\.preventDefault\(\);/', $bloco, 'só confirma se a linha entrou');
    }

    #[TestDox('show: a linha vai para o lugar certo — principal no bloco de fora da rolagem, demais em #clientesOutros')]
    public function testLugarDaLinha(): void
    {
        $bloco = self::blocoClientesDoShow();

        self::assertStringContainsString("String(principal.clienteId ?? '') === clienteId", $bloco);
        self::assertStringContainsString("clientesContainer.querySelector('.cliente-principal')", $bloco);
        self::assertStringContainsString("bloco.className = 'clientes-outros ps-clientes-rolagem';", $bloco);
        self::assertStringContainsString("bloco.id = 'clientesOutros';", $bloco);
        self::assertStringContainsString("anterior.querySelector('.ps-selo-principal')?.remove();", $bloco, 'quem perde o principal perde o selo');
        self::assertStringContainsString('trocaClientePrincipal(principal);', $bloco, 'estrela e Média por CPF seguem o servidor');
    }
}
