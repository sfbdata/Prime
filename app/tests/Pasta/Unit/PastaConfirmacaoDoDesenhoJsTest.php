<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * E7 da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md` §12): a janela de confirmação da
 * Pasta (PAS-034, `pasta-dialogos.js`) ganha a variante AZUL do desenho de 13/10 e substitui os `confirm()`
 * nativos que restavam. Regra do protótipo (`02 - EXPEDIENTES 1.2.3.dc.html` L.6185-6187, `confirmarUI` e
 * `cfVals`): a primeira linha da mensagem (até 90 caracteres) vira o título; sem ela, "Confirmar exclusão"
 * (perigo) ou "Confirmar"; botão "Confirmar" vermelho #b3262e com ⚠ no perigo, azul #0a6fa0 com ✓ fora dele.
 * O protótipo de 13/10 não tem nenhum `confirm()` nativo (15 `confirmarUI`).
 *
 * O que estes testes travam é o que a revisão não pode deixar escapar: nada é enviado antes do "sim" (o
 * envio de formulário é segurado em CAPTURA, antes de qualquer outro ouvinte), o reenvio passa uma vez só,
 * e sem a janela na página a pergunta continua sendo feita pelo navegador. O comportamento com teclado e
 * a cor na tela são da comparação visual (spec §12).
 */
final class PastaConfirmacaoDoDesenhoJsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../';

    private static function ler(string $relativo): string
    {
        return (string) file_get_contents(self::DIR . $relativo);
    }

    private static function dialogos(): string
    {
        return self::ler('public/js/pasta-dialogos.js');
    }

    private static function funcao(string $nome): string
    {
        $js  = self::dialogos();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, 'existe ' . $nome);

        return substr($js, $ini, (int) strpos($js, "\n    }\n", $ini) - $ini);
    }

    #[TestDox('variante azul: classe is-azul na caixa; sem rótulo/ícone, "Confirmar" com ✓ no azul e "Excluir" com lixeira no perigo (padrão de antes)')]
    public function testVarianteAzul(): void
    {
        $c = self::funcao('confirmarPs');
        self::assertStringContainsString("var azul = o.variante === 'azul';", $c);
        self::assertStringContainsString("caixa.classList.toggle('is-azul', azul);", $c, 'a classe sai também no perigo — a janela é reaproveitada');
        self::assertStringContainsString("o.rotulo || (azul ? 'Confirmar' : 'Excluir')", $c);
        self::assertStringContainsString("o.icone || (azul ? 'bi-check2' : 'bi-trash3')", $c);
    }

    #[TestDox('regra do desenho para a mensagem: título até a 1ª quebra (< 90), senão "Confirmar exclusão"/"Confirmar"; ⚠ no perigo, ✓ no azul')]
    public function testRegraDaMensagem(): void
    {
        $f = self::funcao('opcoesDaMensagem');
        self::assertStringContainsString("var i = t.indexOf('\\n');", $f);
        self::assertStringContainsString('var temTitulo = i > 0 && i < 90;', $f);
        self::assertStringContainsString("(perigo ? 'Confirmar exclusão' : 'Confirmar')", $f);
        self::assertStringContainsString("rotulo: 'Confirmar',", $f);
        self::assertStringContainsString("icone: perigo ? 'bi-exclamation-triangle' : 'bi-check2',", $f);
        self::assertStringContainsString("variante: perigo ? 'perigo' : 'azul'", $f);

        $ui = self::funcao('confirmarUIPs');
        self::assertStringContainsString("if (!document.getElementById('psConfirmar')) {", $ui);
        self::assertStringContainsString('return Promise.resolve(window.confirm(String(mensagem || \'\')));', $ui, 'sem a janela, a pergunta continua sendo feita');
    }

    #[TestDox('envio de formulário: segurado em CAPTURA (preventDefault + stopImmediatePropagation) antes de perguntar; reenvio só no "sim", uma vez, com o mesmo botão')]
    public function testEnvioSeguroAteOSim(): void
    {
        $f = self::funcao('ligarEnvioConfirmado');
        self::assertStringContainsString("document.addEventListener('submit', function (e) {", $f);
        self::assertStringContainsString('}, true);', $f, 'fase de captura: roda antes dos ouvintes do formulário');

        $segura = strpos($f, 'e.preventDefault();');
        $para   = strpos($f, 'e.stopImmediatePropagation();');
        $pergunta = strpos($f, 'confirmarUIPs(pergunta.mensagem, pergunta.perigo)');
        self::assertNotFalse($segura);
        self::assertNotFalse($para);
        self::assertLessThan($pergunta, $segura, 'o envio é segurado ANTES de perguntar');
        self::assertLessThan($pergunta, $para);

        self::assertStringContainsString("if (!sim || !document.contains(form)) { return; }", $f, 'Cancelar, Esc ou fundo: nada sai');
        self::assertStringContainsString('if (envioConfirmado.has(form)) { envioConfirmado.delete(form); return; }', $f, 'o reenvio passa uma vez');
        self::assertStringContainsString('if (botao) { form.requestSubmit(botao); } else { form.requestSubmit(); }', $f, 'requestSubmit: os ouvintes de sempre rodam no reenvio');
        self::assertStringContainsString('submitter.form === form && document.contains(submitter)', $f, 'botão que saiu do formulário não derruba o reenvio');
        $tente = substr($f, (int) strpos($f, 'try {'));
        self::assertMatchesRegularExpression('/\\} finally \\{\\s*(\\/\\/[^\\n]*\\n\\s*)*envioConfirmado\\.delete\\(form\\);/', $tente, 'a marca sai mesmo se o reenvio lançar ou a validação barrar');
    }

    #[TestDox('a pergunta do envio vem de data-ps-cf (+ data-ps-cf-perigo) ou de psConfirmarEnvio; o resto é exportado em window')]
    public function testPerguntaDoEnvioEExportacao(): void
    {
        $f = self::funcao('perguntaDoEnvio');
        self::assertStringContainsString("form.hasAttribute('data-ps-cf')", $f);
        self::assertStringContainsString("perigo: form.hasAttribute('data-ps-cf-perigo')", $f);
        self::assertStringContainsString('perguntasDeEnvio.get(form)(form, submitter)', $f);

        $js = self::dialogos();
        foreach (['window.confirmarUIPs = confirmarUIPs;', 'window.psConfirmarEnvio = psConfirmarEnvio;', 'ligarEnvioConfirmado();'] as $trecho) {
            self::assertStringContainsString($trecho, $js);
        }
    }

    #[TestDox('janela: botão azul #0a6fa0 na variante (dc cfVals okBg), texto em pre-line, acessível (alertdialog, aria-modal, título e texto ligados)')]
    public function testMarcacaoECores(): void
    {
        // Desde a E8 a janela mora num partial (Pasta e Expediente); os tokens ficam no próprio fundo.
        $janela = self::ler('templates/_partials/_janela_confirmacao.html.twig');
        self::assertMatchesRegularExpression('/\.ps-cf-fundo \{[^}]*--cf-ok-azul: #0a6fa0;/', $janela);
        self::assertMatchesRegularExpression('/\.ps-cf-fundo \{[^}]*--cf-ok: #b3262e;/', $janela, 'o vermelho do desenho segue');
        self::assertMatchesRegularExpression('/\.ps-cf-fundo \{[^}]*--cf-cab: #0b6a96;/', $janela, 'cabeçalho do dc nas duas variantes');
        self::assertStringContainsString('.ps-cf.is-azul .ps-cf-btn--ok { background: var(--cf-ok-azul); }', $janela);
        self::assertMatchesRegularExpression('/\.ps-cf-fundo \{[^}]*--cf-cancelar-tx: #34505f;/', $janela, 'texto do Cancelar do dc');
        self::assertStringContainsString('color: var(--cf-cancelar-tx); }', $janela);
        self::assertStringContainsString('--cf-card: var(--ps-card, #fff); --cf-texto: var(--ps-body, #243845);', $janela, 'na Pasta usa os tokens dela; fora, o valor do dc');
        self::assertStringContainsString('.ps-cf-texto { padding: 14px 16px; font-size: 13.5px; line-height: 1.55; color: var(--cf-texto); overflow-wrap: anywhere; }', $janela, 'como o dc: sem pre-line');
        self::assertStringContainsString('<div class="ps-cf" role="alertdialog" aria-modal="true" aria-labelledby="psConfirmarTitulo" aria-describedby="psConfirmarTexto" tabindex="-1">', $janela);

        self::assertStringContainsString("{{ include('_partials/_janela_confirmacao.html.twig') }}", self::ler('templates/pasta/show.html.twig'));
        self::assertStringNotContainsString('id="psConfirmar"', self::ler('templates/pasta/show.html.twig'), 'uma janela só por página');
    }

    #[TestDox('foco ao fechar: volta a quem abriu; se era item de um menu que já fechou, volta ao botão que abre o menu (não ao <body>) — achado na tela')]
    public function testFocoVoltaAoGatilhoDoMenu(): void
    {
        $f = self::funcao('devolverFoco');
        self::assertStringContainsString('if (visivel(foco)) { foco.focus(); return; }', $f);
        self::assertStringContainsString("foco.closest('.ps-pop, [role=\"menu\"]')", $f);
        self::assertStringContainsString("menu.parentElement.querySelector(':scope > [aria-haspopup]')", $f, 'menu sem id (análises da IA): o gatilho irmão');
        self::assertStringContainsString("document.querySelector('[data-ps-pop=\"' + id + '\"], [aria-controls=\"' + id + '\"]')", $f, 'os dois tipos de menu da Pasta');
        self::assertStringContainsString('devolverFoco(aberta.foco);', self::funcao('encerrarConfirmacao'));
        self::assertStringContainsString('foco: o.foco || document.activeElement', self::funcao('confirmarPs'), 'quem pergunta pode dizer para onde o foco volta');
        self::assertStringContainsString('el.getClientRects().length > 0', self::funcao('visivel'));
    }

    #[TestDox('Editar dados roda no DOMContentLoaded: no corpo da página o Bootstrap ainda não existe e o bloco saía cedo (sem pergunta do número, POST direto) — achado na tela, existia no master')]
    public function testEditarDadosNaoMorreAntesDoBootstrap(): void
    {
        $show = self::ler('templates/pasta/show.html.twig');
        $ini  = strpos($show, "var modal = document.getElementById('modalEditarPasta');\n    if (!modal || typeof bootstrap === 'undefined') { return; }");
        self::assertNotFalse($ini);
        $antes = substr($show, $ini - 80, 80);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', function () {", $antes, 'o guarda do Bootstrap só pode rodar depois do base.html.twig carregar o Bootstrap');
        self::assertStringNotContainsString('(function () {', $antes);

        $base = self::ler('templates/base.html.twig');
        self::assertGreaterThan(strpos($base, '{% block body %}'), strpos($base, 'bootstrap.bundle.min.js'), 'premissa: o Bootstrap carrega DEPOIS do corpo da página');
    }

    /**
     * Cada pergunta da Pasta, a variante do desenho e o trecho que a faz. `azul` = sem perigo.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function perguntas(): iterable
    {
        yield 'duplicar pasta — azul (dc L.4511)' => ['templates/pasta/_cabecalho.html.twig', "data-ps-cf=\"{{ 'Duplicar a pasta ' ~ pasta.nup"];
        yield 'restaurar pasta — azul (convenção do dc)' => ['templates/pasta/_cabecalho.html.twig', "variante: 'azul'"];
        yield 'alterar número — azul (dc L.4416)' => ['templates/pasta/show.html.twig', "mensagem: 'Alterar número da pasta\\nAlterar o número da pasta de '"];
        yield 'desvincular processo — vermelho (dc L.3031)' => ['templates/pasta/show.html.twig', 'await window.confirmarUIPs(perguntaDesv, true)'];
        yield 'administrativo sem processo — azul (dc L.6878)' => ['public/js/pasta-processo.js', "window.confirmarUIPs(BJ_T.t('pasta_processo.js.administrativa_titulo') + '\\n' + texto.replace(/\\s*\\n\\s*/g, ' '), false)"];
        yield 'corrigir valor — azul (dc L.3563)' => ['templates/pasta/_financeiro_pagamentos_script.html.twig', 'await window.confirmarUIPs(pergunta, false)'];
        yield 'excluir lançamento — vermelho (dc L.3573)' => ['templates/pasta/_financeiro_pagamentos_script.html.twig', "await window.confirmarUIPs(BJ_T.t('pasta_financeiro.js.excluir_lancamento_titulo') + '\\n' + perguntaEx, true)"];
        yield 'excluir comentário do Financeiro — vermelho "Excluir" (dc comExcluir)' => ['templates/pasta/_financeiro_obs_script.html.twig', 'await window.confirmarPs({ titulo: partesObs[0]'];
        yield 'excluir arquivo do Financeiro — vermelho (dc L.6839)' => ['templates/pasta/_financeiro.html.twig', 'await window.confirmarUIPs(perguntaArq, true)'];
        yield 'excluir meta — vermelho' => ['templates/pasta/_metas.html.twig', 'data-ps-cf="Excluir esta meta? Esta ação não pode ser desfeita." data-ps-cf-perigo'];
        yield 'excluir item do checklist — vermelho' => ['public/js/pasta-checklist.js', "perguntar('Excluir este item do checklist?', true)"];
        yield 'substituir modelo do checklist — vermelho (apaga os itens do modelo)' => ['public/js/pasta-checklist.js', "perguntar('Substituir modelo\\nJá existe o modelo \"' + data.nome"];
        yield 'excluir modelo do checklist — vermelho' => ['public/js/pasta-checklist.js', "perguntar('Excluir o modelo \"' + nome + '\"? Os checklists já aplicados nas pastas não mudam.', true)"];
        yield 'excluir documentos/pastas (lote) — vermelho (dc L.5144)' => ['public/js/pasta-explorador.js', 'window.confirmarUIPs(pergunta, true)'];
        yield 'excluir análise da IA no Push — vermelho (dc L.5351)' => ['public/js/pasta-ia-push.js', 'await window.confirmarUIPs(confirmar, true, voltar)'];
        yield 'excluir análise da IA no drawer — vermelho (dc L.5351)' => ['public/js/pasta-ia-agentes.js', 'await window.confirmarUIPs(confirmar, true, voltar)'];
        yield 'excluir nota técnica — vermelho (dc L.5603)' => ['public/js/nota-tecnica.js', "var pergunta = 'Excluir esta nota técnica?';"];
        yield 'excluir arquivo do Financeiro — texto do dc com o nome (L.6839)' => ['templates/pasta/_financeiro.html.twig', "BJ_T.t('pasta_financeiro.js.excluir_arquivo_nome', { nome: nomeArq })"];
    }

    #[TestDox('Financeiro: o título "Excluir lançamento" e a frase do arquivo com o nome são os do desenho, no catálogo')]
    public function testTextosDoFinanceiroNoCatalogo(): void
    {
        // Desde a extração do Financeiro (idioma-do-sistema) o texto mora no catálogo: o script tem a chave, o catálogo o texto do desenho.
        $js = Yaml::parseFile(self::DIR . 'translations/messages+intl-icu.pt_BR.yaml')['pasta_financeiro']['js'];
        self::assertSame('Excluir lançamento', $js['excluir_lancamento_titulo']);
        self::assertSame('Excluir o arquivo "{nome}" do Financeiro?', $js['excluir_arquivo_nome']);
    }

    #[DataProvider('perguntas')]
    #[TestDox('$_dataName')]
    public function testCadaPerguntaNaJanelaComAVarianteDoDesenho(string $arquivo, string $trecho): void
    {
        self::assertStringContainsString($trecho, self::ler($arquivo));
    }

    #[TestDox('O título da pergunta do administrativo sem processo mora no catálogo, com o texto do desenho (dc L.6878)')]
    public function testTituloDoAdministrativoNoCatalogo(): void
    {
        $catalogo = Yaml::parseFile(self::DIR . 'translations/messages+intl-icu.pt_BR.yaml');

        self::assertSame('Marcar como administrativo', $catalogo['pasta_processo']['js']['administrativa_titulo']);
    }

    /** @return iterable<string, array{string}> */
    public static function arquivosDaPasta(): iterable
    {
        foreach ([
            'templates/pasta/_cabecalho.html.twig', 'templates/pasta/show.html.twig', 'templates/pasta/_metas.html.twig',
            'templates/pasta/_financeiro.html.twig', 'templates/pasta/_financeiro_obs_script.html.twig',
            'templates/pasta/_financeiro_pagamentos_script.html.twig', 'public/js/pasta-processo.js',
            'public/js/pasta-checklist.js', 'public/js/pasta-explorador.js', 'public/js/pasta-ia-push.js',
            'public/js/pasta-ia-agentes.js', 'public/js/nota-tecnica.js',
        ] as $arquivo) {
            yield $arquivo => [$arquivo];
        }
    }

    #[DataProvider('arquivosDaPasta')]
    #[TestDox('nenhum confirm() nativo direto em $arquivo — só a reserva quando a janela não existe')]
    public function testSemConfirmNativoDireto(string $arquivo): void
    {
        $fonte = self::ler($arquivo);
        preg_match_all('/(?<![\w.])(?:window\.)?confirm\(/', $fonte, $todos, PREG_OFFSET_CAPTURE);
        foreach ($todos[0] as [, $pos]) {
            // Reserva = o `confirm` está no ramo "sem janela" de uma chamada à janela logo antes.
            $antes = substr($fonte, max(0, $pos - 220), min(220, $pos));
            $linha = trim((string) strtok(substr($fonte, (int) strrpos(substr($fonte, 0, $pos), "\n") + 1), "\n"));
            self::assertMatchesRegularExpression('/confirmarUIPs|confirmarPs|psConfirmarEnvio/', $antes, $arquivo . ': confirm() fora da reserva → ' . $linha);
        }
    }

    #[TestDox('análises da IA: uma pergunta por vez por marca no formulário, sem desabilitar o botão — o foco precisa voltar a ele (achado da revisão)')]
    public function testAnaliseDaIaNaoDesabilitaOBotaoAoPerguntar(): void
    {
        foreach (['public/js/pasta-ia-push.js', 'public/js/pasta-ia-agentes.js'] as $arquivo) {
            $js = self::ler($arquivo);
            self::assertStringContainsString("if (form.dataset.perguntando) { return; }", $js, $arquivo);
            self::assertStringContainsString('delete form.dataset.perguntando;', $js, $arquivo);
            self::assertStringNotContainsString('travar.disabled = true', $js, $arquivo);
            self::assertStringContainsString('await window.confirmarUIPs(confirmar, true, voltar)', $js, $arquivo . ': o menu fecha antes do envio — foco explícito no ⋮');
        }
    }
}
