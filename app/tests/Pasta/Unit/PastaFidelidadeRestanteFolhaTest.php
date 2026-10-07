<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Lote P13, lido como FOLHA (não há navegador no PHPUnit): o CSS de N10/N11 e o JS das duas
 * pendências pequenas — (a) a âncora `#pasta-msg-<id>` que cai num registro escondido em
 * "Ver anotações anteriores" e (b) o bloco de Clientes da aba Dados (vazio, contagem, selo
 * "Principal" e a constante morta `pastaId`). O que se prova é o contrato do código; o efeito
 * na tela é smoke do dono.
 */
#[CoversNothing]
final class PastaFidelidadeRestanteFolhaTest extends TestCase
{
    private const CSS       = __DIR__ . '/../../../public/css/pasta-show.css';
    private const PASTA_JS  = __DIR__ . '/../../../public/js/pasta-show.js';
    private const SHOW      = __DIR__ . '/../../../templates/pasta/show.html.twig';
    private const DETALHES  = __DIR__ . '/../../../templates/pasta/_detalhes_obs.html.twig';

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents($arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** O corpo de uma função JS `function nome(` até a próxima `\n    function ` do mesmo nível. */
    private static function funcao(string $fonte, string $nome): string
    {
        $inicio = strpos($fonte, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, $nome . ' existe');
        $fim = strpos($fonte, "\n    function ", $inicio + 1);
        if ($fim === false) {
            $fim = strlen($fonte);
        }

        return substr($fonte, $inicio, $fim - $inicio);
    }

    /** O script do modal de clientes do show (do `getElementById('modalAdicionarCliente')` em diante). */
    private static function blocoClientes(): string
    {
        $twig   = self::ler(self::SHOW);
        $inicio = strpos($twig, "const modal = document.getElementById('modalAdicionarCliente');");
        self::assertNotFalse($inicio);
        $fim = strpos($twig, '</script>', $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    // ── N10 / N11 (CSS) ──────────────────────────────────────────────────────

    #[TestDox('N10: o rótulo do documento ("CPF") no trilho tem peso normal, não 600')]
    public function testRotuloDoDocumentoPesoNormal(): void
    {
        $css = self::ler(self::CSS);

        self::assertMatchesRegularExpression('/\.ps-clientes \.cliente-doc-rotulo \{[^}]*font-weight: inherit;/', $css);
        self::assertDoesNotMatchRegularExpression('/\.ps-clientes \.cliente-doc-rotulo \{[^}]*font-weight: 600/', $css);
    }

    #[TestDox('N11: a linha do cliente não ganha fundo no hover e o nome do documento não fica azul')]
    public function testHoverDoTrilho(): void
    {
        $css = self::ler(self::CSS);

        self::assertDoesNotMatchRegularExpression('/\.ps-clientes \.cliente-linha:hover \{[^}]*background/', $css);
        self::assertDoesNotMatchRegularExpression('/\.ps-doc:hover \.ps-doc-nome \{[^}]*color/', $css);
        // O fundo do documento continua (#f1f7fb, `--ps-hover-doc`).
        self::assertMatchesRegularExpression('/\.ps-doc:hover,\s*\.ps-doc:focus-visible \{ background: var\(--ps-hover-doc\)/', $css);
        self::assertMatchesRegularExpression('/--ps-hover-doc:\s+#f1f7fb;/', $css);
    }

    #[TestDox('N7: o ⋮ da observação tem 28px, a cor #8496a3 (--ps-faint) e o menu é fixo')]
    public function testMedidasDoMaisDaObservacao(): void
    {
        $css = self::ler(self::CSS);

        self::assertMatchesRegularExpression('/\.ps-detalhes-obs \.ps-obs-menu-btn \{[^}]*width: 28px;[^}]*height: 28px;[^}]*color: var\(--ps-faint\);[^}]*font-size: 14px;/', $css);
        self::assertMatchesRegularExpression('/\.ps-detalhes-obs \.ps-obs-menu \{[^}]*position: fixed;/', $css);
        self::assertMatchesRegularExpression('/\.ps-detalhes-obs \.ps-obs-menu\[hidden\] \{ display: none; \}/', $css);
    }

    #[TestDox('N7: "Copiar texto" copia o texto lido do cartão e avisa "Texto copiado"')]
    public function testCopiarTextoDaObservacao(): void
    {
        $fonte = self::ler(self::DETALHES);
        $copiar = self::funcao($fonte, 'copiarTextoObs');

        self::assertStringContainsString(".querySelector('.obs-conteudo-det .ql-editor')", $copiar);
        self::assertStringContainsString('corpo.innerText.trim()', $copiar, 'texto da tela, sem a marcação do editor');
        self::assertStringContainsString('navigator.clipboard.writeText(texto)', $copiar);
        self::assertStringContainsString("avisar('Texto copiado')", $copiar);
        self::assertStringContainsString("e.target.closest('.js-obs-copiar')", $fonte);
        self::assertStringContainsString("e.target.closest('.js-obs-menu')", $fonte);
    }

    // ── (a) âncora #pasta-msg-<id> ───────────────────────────────────────────

    #[TestDox('(a) a âncora #pasta-msg-<id> abre "Ver anotações anteriores" quando o registro está escondido e rola até ele')]
    public function testAncoraAbreOsAnteriores(): void
    {
        $js = self::ler(self::PASTA_JS);

        $chamadaAba = strpos($js, "        abaDoFragmento();\n");
        $chamadaMsg = strpos($js, "        mensagemDoFragmento();\n");
        self::assertNotFalse($chamadaAba);
        self::assertNotFalse($chamadaMsg);
        self::assertLessThan($chamadaMsg, $chamadaAba, 'roda no DOMContentLoaded, depois da aba do fragmento');
        self::assertLessThan($chamadaMsg, (int) strpos($js, "        verAnotacoesAnteriores();\n"), 'o botão já tem o seu clique ligado');

        $fn = self::funcao($js, 'mensagemDoFragmento');
        self::assertStringContainsString('/^#pasta-msg-\d+$/.test(hash)', $fn, 'só o fragmento de registro, com id numérico');
        self::assertStringContainsString("alvo.classList.contains('ps-anotacao--extra')", $fn);
        self::assertStringContainsString("alvo.closest('.ps-anotacao--extra')", $fn, 'resposta dentro de uma conversa escondida');
        self::assertStringContainsString("document.getElementById('psAnotacoesMais')", $fn);
        self::assertStringContainsString('botao.click();', $fn, 'abre pelo próprio botão: mesmo efeito do clique');
        self::assertStringContainsString("document.getElementById('dados-tab')", $fn);
        self::assertStringContainsString('alvo.scrollIntoView(', $fn);

        $abre  = strpos($fn, 'botao.click();');
        $rola  = strpos($fn, 'alvo.scrollIntoView(');
        self::assertLessThan($rola, $abre, 'abre o bloco ANTES de rolar');
    }

    // ── (b) bloco de Clientes ────────────────────────────────────────────────

    #[TestDox('(b) desvincular o último cliente recoloca o vazio do Twig (não a marcação antiga) e atualiza a contagem')]
    public function testRemoveClienteRowRecolocaOVazioDoTwig(): void
    {
        $bloco  = self::blocoClientes();
        $remove = self::funcao($bloco, 'removeClienteRow');

        self::assertStringNotContainsString('text-muted small mb-0', $bloco, 'a marcação antiga do vazio saiu');
        self::assertStringNotContainsString('Nenhum cliente vinculado.</div>', $bloco);
        self::assertStringContainsString("const restantes = clientesContainer.querySelectorAll('.cliente-linha').length;", $remove);
        self::assertStringContainsString('atualizarContagemClientes(restantes);', $remove);
        self::assertStringContainsString('clientesContainer.appendChild(montaPlaceholderClientes());', $remove);
        self::assertStringContainsString("querySelectorAll('.cliente-principal, .clientes-outros')", $remove, 'bloco vazio sai, como no Twig');

        $vazio = self::funcao($bloco, 'montaPlaceholderClientes');
        self::assertStringContainsString("div.id = 'clientesPlaceholder';", $vazio);
        self::assertStringContainsString('clientesContainer.dataset.nomeCliente', $vazio);
        self::assertStringContainsString("div.className = 'ps-vazio';", $vazio);
        self::assertStringContainsString("titulo.textContent = 'Nenhum cliente vinculado';", $vazio);
        self::assertStringContainsString("nota.textContent = 'Vincule o cliente para calcular a média por CPF.';", $vazio);
        self::assertStringContainsString("div.className = 'ps-cliente';", $vazio);
        self::assertStringContainsString('nome.textContent = nomeSolto;', $vazio, 'dado do usuário só por textContent');
        self::assertStringNotContainsString('innerHTML', $vazio);

        // E a marcação bate com a do Twig (mesmas classes nos dois estados).
        $trilho = self::ler(__DIR__ . '/../../../templates/pasta/_dados_trilho.html.twig');
        self::assertStringContainsString('<div id="clientesPlaceholder" class="ps-vazio">', $trilho);
        self::assertStringContainsString('<div id="clientesPlaceholder" class="ps-cliente">', $trilho);
        self::assertStringContainsString('<div class="ps-vazio-titulo">Nenhum cliente vinculado</div>', $trilho);
    }

    #[TestDox('(b) trocar o principal pela estrela move o selo "Principal" e a linha para o bloco do principal')]
    public function testTrocaClientePrincipalMoveOSelo(): void
    {
        $troca = self::funcao(self::blocoClientes(), 'trocaClientePrincipal');

        self::assertStringContainsString("novoSelo.className = 'ps-selo-principal';", $troca);
        self::assertStringContainsString("novoSelo.textContent = 'Principal';", $troca);
        self::assertStringContainsString('nome.appendChild(novoSelo);', $troca);
        self::assertStringContainsString('selo.remove();', $troca, 'quem perde o principal perde o selo');
        self::assertStringContainsString("!linhaNova.closest('.cliente-principal')", $troca);
        self::assertStringContainsString('blocoPrincipal.appendChild(linhaNova);', $troca);
        self::assertStringContainsString('blocoDosOutros().prepend(anterior);', $troca, 'o anterior desce para os outros');
        // A estrela continua sendo trocada como antes.
        self::assertStringContainsString("atual.replaceWith(montaEstrelaPrincipal(clienteId, ehNovo, linha.dataset.tokenPrincipal ?? ''));", $troca);
    }

    #[TestDox('(b) a constante morta `pastaId` saiu do bloco de clientes')]
    public function testSemConstantePastaId(): void
    {
        self::assertDoesNotMatchRegularExpression('/\bconst pastaId\b/', self::blocoClientes());
    }

    #[TestDox('desvincular processo: o submit delegado do show lê o texto do desenho no data-confirmar do form')]
    public function testConfirmacaoDeDesvincularLeOAtributo(): void
    {
        self::assertStringContainsString(
            "if (!confirm(form.dataset.confirmar || 'Desvincular o processo desta pasta?')) {",
            self::ler(self::SHOW)
        );
    }
}
