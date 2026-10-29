<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O script da janela "Vincular processo" (show.html.twig) lido como FOLHA, contra o desenho 02 de 18/10
 * (`procMask`, `docMask`, `pvBuscarCNJ`, `addMov`, `semRem`, `procSalvarVinc`; N:3135-3151 e N:4507-4564).
 * Sem navegador no PHPUnit: o que se prova é o caminho de cada comportamento.
 */
#[CoversNothing]
#[Group('pasta')]
final class VincularProcessoJanelaJsTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function script(): string
    {
        $twig   = (string) file_get_contents(self::TEMPLATE);
        $inicio = strpos($twig, '/* Janela "Vincular processo"');
        self::assertNotFalse($inicio, 'o script da janela existe');
        $fim = strpos($twig, '</script>', $inicio);
        self::assertNotFalse($fim);

        return substr($twig, $inicio, $fim - $inicio);
    }

    private function funcao(string $nome): string
    {
        $js     = $this->script();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, "a função {$nome} existe");
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    private function trecho(string $de, string $ate): string
    {
        $js     = $this->script();
        $inicio = strpos($js, $de);
        self::assertNotFalse($inicio, $de);
        $fim = strpos($js, $ate, $inicio);
        self::assertNotFalse($fim, $ate);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('Máscara do número CNJ enquanto digita: os 20 dígitos em NNNNNNN-DD.AAAA.J.TT.OOOO (procMask)')]
    public function testMascaraDoNumero(): void
    {
        $mask = $this->funcao('mpProcMask');
        self::assertStringContainsString(".replace(/\\D/g, '').slice(0, 20)", $mask);
        foreach (["'-' + d.slice(7, 9)", "'.' + d.slice(9, 13)", "'.' + d.slice(13, 14)", "'.' + d.slice(14, 16)", "'.' + d.slice(16, 20)"] as $parte) {
            self::assertStringContainsString($parte, $mask);
        }
        self::assertStringContainsString('numeroEl.value = mpProcMask(numeroEl.value);', $this->script());
    }

    #[TestDox('Documento da parte: máscara de CPF/CNPJ (docMask) aplicada ao digitar nos campos .mp-doc')]
    public function testMascaraDoDocumento(): void
    {
        $mask = $this->funcao('mpDocMask');
        self::assertStringContainsString(".slice(0, 14)", $mask);
        self::assertStringContainsString("if (d.length <= 11)", $mask);
        self::assertStringContainsString(".replace(/\\.(\\d{3})(\\d)/, '.$1/$2')", $mask);
        self::assertStringContainsString("e.target.classList.contains('mp-doc')) e.target.value = mpDocMask(e.target.value)", $this->script());
    }

    #[TestDox('"Adicionar movimentação" põe a linha nova no TOPO; "Adicionar parte", no fim; índices nunca se repetem')]
    public function testMovimentacaoNovaNoTopo(): void
    {
        $js = $this->script();
        self::assertStringContainsString("getElementById('mp-movimentacoesContainer').insertAdjacentHTML('afterbegin', mpMovRowHtml(mpProxIdx++))", $js);
        self::assertStringContainsString("getElementById('mp-partesContainer').insertAdjacentHTML('beforeend', mpParteRowHtml(mpProxIdx++))", $js);
    }

    #[TestDox('A lixeira fica desabilitada com uma linha só (semRem) e remove a própria linha')]
    public function testLixeiraDesabilitadaComUmaLinha(): void
    {
        self::assertStringContainsString('btn.disabled = linhas < 2;', $this->funcao('mpUpdateRemoveButtons'));
        $clique = $this->trecho("var lixeira = e.target.closest('.mp-lixeira');", 'mpUpdateRemoveButtons();');
        self::assertStringContainsString('lixeira.disabled) return;', $clique);
        self::assertStringContainsString("lixeira.closest('.mp-linha')", $clique);
        foreach (['mpParteRowHtml', 'mpMovRowHtml'] as $linha) {
            self::assertStringContainsString('class="mp-lixeira ', $this->funcao($linha));
            self::assertStringContainsString('<i class="bi bi-trash3"></i></button>', $this->funcao($linha));
        }
    }

    #[TestDox('"Buscar no CNJ": "Buscando…" com bi-arrow-repeat no próprio botão; número incompleto é erro vermelho')]
    public function testEstadoBuscando(): void
    {
        $estado = $this->funcao('mpCnjEstado');
        self::assertStringContainsString("buscando ? 'bi bi-arrow-repeat' : 'bi bi-search'", $estado);
        self::assertStringContainsString("T(buscando ? 'buscando' : 'buscar_cnj')", $estado);

        $busca = $this->trecho("btnCnj.addEventListener('click'", 'function mpFaltando()');
        self::assertStringContainsString('if (mpCnjBuscando) return;', $busca);
        self::assertStringContainsString("mpDefinirErro(T('numero_completo'));", $busca);
        self::assertStringContainsString('mpCnjEstado(true);', $busca);
        self::assertStringContainsString('mpCnjEstado(false);', $busca);
        self::assertStringNotContainsString('mp-loadingOverlay', $this->script(), 'sem o spinner separado');
    }

    #[TestDox('Vincular: "Preencha: ..." no clique, de volta à aba Informações; sucesso do principal avisa como o desenho')]
    public function testEnvio(): void
    {
        $envio = $this->trecho("form.addEventListener('submit'", '/* ── Fechar a janela');
        self::assertStringContainsString("mpIrSubaba('modal-proc-info');", $envio);
        self::assertStringContainsString("mpDefinirErro(T('preencha', { campos: falta.join(', ') }));", $envio);
        self::assertStringContainsString("window.avisarPs(T('vinculado_aviso'))", $envio);
        self::assertStringContainsString("icone.className = 'bi bi-check2';", $envio);
        self::assertStringContainsString("mpDefinirErro(T('selecione'))", $envio);
    }

    #[TestDox('Busca de processo existente: sem Select2, abre ao focar, filtra ao digitar e responde a setas, Enter e Esc')]
    public function testBuscaDeProcesso(): void
    {
        $js = $this->script();
        self::assertStringNotContainsString('select2', $js);
        self::assertStringNotContainsString('minimumInputLength', $js);
        self::assertStringContainsString("buscaInput.addEventListener('focus', function () { buscaQuer = true; mpBuscar(true); });", $js);
        self::assertStringContainsString("buscaInput.addEventListener('input', function () { buscaQuer = true; mpBuscar(false); });", $js);
        foreach (["e.key === 'ArrowDown'", "e.key === 'ArrowUp'", "e.key === 'Enter'"] as $tecla) {
            self::assertStringContainsString($tecla, $js);
        }
        self::assertStringContainsString('.slice(0, 30)', $this->funcao('mpBuscar'));

        $lista = $this->funcao('mpMostrarLista');
        self::assertStringContainsString('num.textContent = mpNumeroVisivel(p.numero);', $lista);
        self::assertStringContainsString("' · ' + mpOrigem(p.origem)", $lista);
        self::assertStringNotContainsString('innerHTML', $lista, 'a lista é montada por DOM e textContent');

        $origem = $this->funcao('mpOrigem');
        foreach (["'origem_push'", "'origem_apensado'", "'origem_pasta'"] as $chave) {
            self::assertStringContainsString($chave, $origem);
        }
        self::assertStringContainsString('campoProcesso.value = String(p.id);', $this->funcao('mpSelecionar'));
    }

    #[TestDox('Janela do desenho, sem o modal do Bootstrap: abre pelos [data-vp-abrir], fecha no X/Cancelar, no clique fora e no Esc, e limpa ao fechar')]
    public function testAbrirEFechar(): void
    {
        $js = $this->script();
        self::assertStringNotContainsString('bootstrap.Modal', $js);
        self::assertStringNotContainsString('bs.modal', $js);
        self::assertStringContainsString("e.target.closest('[data-vp-abrir]')", $js);
        self::assertStringContainsString("if (e.target === modalEl || e.target.closest('[data-vp-fechar]')) mpFechar();", $js, 'clique fora (`fechar` na sobreposição, `parar` na caixa)');
        self::assertStringContainsString("if (e.key === 'Escape' && !modalEl.hidden) mpFechar();", $js);

        $abrir = $this->funcao('mpAbrir');
        self::assertStringContainsString("new CustomEvent('vp:abrir', { detail: { gatilho: mpGatilho } })", $abrir);
        self::assertStringContainsString('modalEl.hidden = false;', $abrir);
        self::assertStringContainsString('buscaInput.focus();', $abrir, 'o autofocus do desenho na busca');

        $fechar = $this->funcao('mpFechar');
        self::assertStringContainsString('modalEl.hidden = true;', $fechar);
        self::assertStringContainsString('mpLimpar();', $fechar);
        self::assertStringContainsString('form.reset();', $this->funcao('mpLimpar'));
        // O aviso é o do desenho (`avisoSt`), sem o alert do Bootstrap.
        self::assertStringContainsString("'mp-aviso' + (erro ? ' mp-aviso--erro' : '')", $this->funcao('mpAtualizarAviso'));
        self::assertStringNotContainsString("'alert ", $js);
    }

    #[TestDox('Tribunal enquanto digita: a sigla sai da tabela do servidor (data-tribunais), não de uma cópia no JS')]
    public function testTribunalEnquantoDigita(): void
    {
        $js = $this->script();
        self::assertStringContainsString("JSON.parse(modalEl.dataset.tribunais || '{}')", $js);
        $trib = $this->funcao('mpProcTrib');
        self::assertStringContainsString('mpTribunais[d.charAt(13)]', $trib);
        self::assertStringContainsString('segmento[d.slice(14, 16)]', $trib);
        self::assertStringContainsString("document.getElementById('mp-siglaTribunal').value = mpProcTrib(numeroEl.value);", $js);
        self::assertStringNotContainsString("'TJDFT'", $js, 'sem tabela copiada à mão');
    }

    #[TestDox('Origem sem número (pasta que o usuário não vê): "Outra pasta" e "Apensado · outra pasta"')]
    public function testOrigemOutraPasta(): void
    {
        $origem = $this->funcao('mpOrigem');
        self::assertStringContainsString("if (!origem.pasta) return T(origem.tipo === 'apensado' ? 'origem_apensado_outra' : 'origem_outra_pasta');", $origem);
    }

    #[TestDox('Aviso da pasta (o gmAviso do desenho): entra com o pcIn de N:46/671 (.25s, sobe 10px), sem perder o centro')]
    public function testEntradaDoAviso(): void
    {
        $twig = (string) file_get_contents(self::TEMPLATE);
        self::assertStringContainsString('.ps-aviso { animation: psAvisoEntra .25s both; }', $twig);
        self::assertStringContainsString('@keyframes psAvisoEntra { from { opacity: 0; transform: translate3d(-50%, 10px, 0); } to { opacity: 1; transform: translate3d(-50%, 0, 0); } }', $twig);
    }

    #[TestDox('Esc como o desenho (pvVals.tecla, N:3151): um Esc fecha a janela inteira, mesmo com a lista aberta')]
    public function testEscFechaAJanelaInteira(): void
    {
        $teclado = $this->trecho("buscaInput.addEventListener('keydown'", "document.getElementById('mp-busca-trocar')");
        self::assertStringNotContainsString('Escape', $teclado, 'a busca não segura o Esc');
        self::assertStringNotContainsString('stopPropagation', $teclado);
        self::assertStringNotContainsString('Bootstrap', $teclado);
        self::assertStringContainsString("if (e.key === 'Escape' && !modalEl.hidden) mpFechar();", $this->script());
        self::assertStringContainsString('mpFecharLista();', $this->funcao('mpLimpar'), 'fechar a janela fecha a lista');
    }

    #[TestDox('Sem d-none do Bootstrap: as partes da janela ligam e desligam pelo atributo hidden')]
    public function testPartesPeloHidden(): void
    {
        $js = $this->script();
        self::assertStringNotContainsString('d-none', $js);
        self::assertStringContainsString("document.getElementById('modal-modo-novo').hidden = !ehNovo;", $js);
        self::assertStringContainsString("document.getElementById('modal-modo-existente').hidden = ehNovo;", $js);
        self::assertStringContainsString('cnjOk.hidden = false;', $js);
        self::assertStringContainsString('feedbackEl.hidden      = !texto;', $this->funcao('mpAtualizarAviso'));
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/pasta-apensados.css');
        self::assertStringContainsString('#modalVincularProcesso [hidden] { display: none !important; }', $css);
    }

    #[TestDox('Acessibilidade: abas com aria-selected atualizado; foco volta ao ⋮ quando o item do menu sumiu; sem prisão de foco')]
    public function testAcessibilidade(): void
    {
        self::assertStringContainsString("aba.setAttribute('aria-selected', ativa ? 'true' : 'false');", $this->funcao('mpAplicarModo'));
        self::assertStringContainsString("t.setAttribute('aria-selected', ativa ? 'true' : 'false');", $this->funcao('mpIrSubaba'));

        $destino = $this->funcao('mpDestinoDoFoco');
        self::assertStringContainsString('if (gatilho.getClientRects().length) return gatilho;', $destino);
        self::assertStringContainsString('[data-ps-pop="\' + id + \'"], [aria-controls="\' + id + \'"]', $destino);
        self::assertStringContainsString('var destino = mpDestinoDoFoco(mpGatilho);', $this->funcao('mpFechar'));
        self::assertStringNotContainsString("'Tab'", $this->script(), 'sem prisão de foco');
    }
}
