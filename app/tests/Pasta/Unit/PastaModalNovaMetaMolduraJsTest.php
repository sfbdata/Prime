<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * PMC-038 — moldura do modal "Nova meta" (desenho 1.2.3, `salvarSt`/`faltaTxt`/`recolher`/
 * `arrastar`). Sem navegador no PHPUnit: o JS é lido como folha. Prova-se o texto exato do
 * desenho para o botão desabilitado e pronto, que título, descrição, prazo e responsável são cobrados
 * nessa ordem (D-META, D:4044-4048), que a conta refaz ao abrir (outros scripts preenchem por
 * `value`) e quando o calendário ou os chips mudam (`change`), que o botão "Nova meta" abre com o
 * prazo em hoje + 7 dias (D:4045) e que fechar desfaz recolher e arrasto. O efeito é do smoke do dono.
 */
final class PastaModalNovaMetaMolduraJsTest extends TestCase
{
    use TextoDosScriptsDaPasta;

    private const JS_METAS = __DIR__ . '/../../../public/js/pasta-metas.js';
    private const SHOW     = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function bloco(): string
    {
        $js     = (string) file_get_contents(self::JS_METAS);
        $inicio = strpos($js, 'function faltaNenhuma() {');
        self::assertNotFalse($inicio);

        return substr($js, $inicio);
    }

    #[TestDox('roda no carregamento e usa os textos do desenho: "Preencha: …" e "Criar meta e notificar os responsáveis"')]
    public function testTextosDoDesenho(): void
    {
        $js = (string) file_get_contents(self::JS_METAS);
        self::assertMatchesRegularExpression('/function iniciar\(\) \{[^}]*molduraDoModalDeMeta\(\);/', $js);

        $bloco = $this->bloco();
        // Função, não constante: o script roda no parse, antes do bj-traducao.js (spec idioma-do-sistema §20.9).
        self::assertStringContainsString("function faltaNenhuma() { return BJ_T.t('pasta_scripts.js.metas.criar_e_notificar'); }", $bloco);
        self::assertStringContainsString("botao.title = falta.length > 0 ? BJ_T.t('pasta_scripts.js.metas.preencha', { campos: falta.join(', ') }) : faltaNenhuma();", $bloco);
        self::assertTextoDoScript('Criar meta e notificar os responsáveis', 'metas.criar_e_notificar', $bloco);
        self::assertTextoDoScript('Preencha: {campos}', 'metas.preencha', $bloco);
        self::assertStringContainsString('botao.disabled = falta.length > 0;', $bloco);
    }

    #[TestDox('D-META: cobra título, descrição, prazo, responsável e etapa, nessa ordem e com "responsável" no singular, como o desenho e o servidor')]
    public function testCobraOsCincoNaOrdemDoDesenho(): void
    {
        $bloco  = $this->bloco();
        $inicio = (int) strpos($bloco, 'function faltaNaMeta(');
        $funcao = substr($bloco, $inicio, (int) strpos($bloco, 'return falta;', $inicio) - $inicio);
        self::assertStringContainsString('function faltaNaMeta(titulo, descricao, prazo, responsaveis, etapa)', $funcao);
        $linhas = [
            "titulo.value.trim() === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_titulo')); }",
            "descricao.value.trim() === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_descricao')); }",
            "prazo.value === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_prazo')); }",
            "responsaveis.selectedOptions.length === 0) { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_responsavel')); }",
            // O desenho cobra a etapa no `inval` (L4048) mas a esquece no `falta`: aqui ela entra, no fim.
            "etapa.value === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_etapa')); }",
        ];
        $posicoes = array_map(static fn (string $l): int|false => strpos($funcao, $l), $linhas);
        self::assertNotContains(false, $posicoes, 'as cinco cobranças');
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'título, descrição, prazo, responsável, etapa (D:4048 `falta` + a etapa)');
        self::assertTextoDoScript('título', 'metas.falta_titulo', $funcao);
        self::assertTextoDoScript('descrição', 'metas.falta_descricao', $funcao);
        self::assertTextoDoScript('prazo', 'metas.falta_prazo', $funcao);
        self::assertTextoDoScript('responsável', 'metas.falta_responsavel', $funcao);
        self::assertTextoDoScript('etapa', 'metas.falta_etapa', $funcao);
        self::assertSame(5, substr_count($funcao, 'falta.push('), 'nada além dos cinco');
    }

    #[TestDox('o calendário, os atalhos e os chips disparam change no campo enviado: a conta refaz neles também')]
    public function testRefazNoCalendarioENosChips(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("var prazo = document.getElementById('tarefaPrazo');", $bloco);
        self::assertStringContainsString("var responsaveis = document.getElementById('tarefaResponsavel');", $bloco);
        self::assertStringContainsString("var etapa = document.getElementById('tarefaEtapa');", $bloco);
        self::assertStringContainsString('[titulo, descricao, prazo, responsaveis, etapa].forEach(function (el) {', $bloco);
        self::assertStringContainsString('var falta = faltaNaMeta(titulo, descricao, prazo, responsaveis, etapa);', $bloco);
        self::assertStringContainsString("el.addEventListener('change', conferir);", $bloco);

        // Quem dispara o `change`: o calendário e os chips (pasta-dialogos.js) e os atalhos (este arquivo).
        $dialogos = (string) file_get_contents(__DIR__ . '/../../../public/js/pasta-dialogos.js');
        self::assertStringContainsString("select.dispatchEvent(new Event('change', { bubbles: true }));", $dialogos);
        self::assertStringContainsString("input.dispatchEvent(new Event('change', { bubbles: true }));", $dialogos);
    }

    #[TestDox('D:4045: aberto pelo botão "Nova meta" (relatedTarget), o prazo vazio vem em hoje + 7 dias corridos; aberto por script, não')]
    public function testNovaMetaAbreComPrazoEmSeteDias(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString('d.setDate(d.getDate() + 7);', $bloco);
        self::assertStringContainsString("gatilho.closest('[data-ps-nova-meta]')", $bloco);
        self::assertStringContainsString("prazo.value !== ''", $bloco, 'quem já passou um prazo mantém o seu');
        self::assertStringContainsString('prazo.value = daquiASeteDias();', $bloco);

        $metas = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/_metas.html.twig');
        self::assertMatchesRegularExpression('/data-bs-target="#modalCriarTarefa" data-ps-nova-meta>/', $metas);
    }

    #[TestDox('a conta refaz ao digitar e ao abrir o modal (o Push e a IA preenchem por value)')]
    public function testRefazAoAbrir(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("modal.addEventListener('show.bs.modal', conferir);", $bloco);
        self::assertStringContainsString("modal.addEventListener('shown.bs.modal', conferir);", $bloco);
        self::assertStringContainsString("el.addEventListener('input', conferir);", $bloco);
    }

    #[TestDox('recolher troca dica e ícone do desenho; fechar desfaz recolher e arrasto')]
    public function testRecolherEFechar(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("var dica = rec ? BJ_T.t('pasta_scripts.js.metas.expandir') : BJ_T.t('pasta_scripts.js.metas.recolher');", $bloco);
        self::assertTextoDoScript('Expandir', 'metas.expandir', $bloco);
        self::assertTextoDoScript('Recolher (continue vendo a lista)', 'metas.recolher', $bloco);
        self::assertStringContainsString("icone.className = rec ? 'bi bi-chevron-bar-expand' : 'bi bi-dash-lg';", $bloco);
        self::assertStringContainsString("alca.addEventListener('dblclick'", $bloco);
        $fechar = substr($bloco, (int) strpos($bloco, "modal.addEventListener('hidden.bs.modal'"));
        self::assertStringContainsString("janela.style.left = '';", $fechar);
        self::assertStringContainsString("janela.style.top = '';", $fechar);
        self::assertStringContainsString("if (janela.classList.contains('is-recolhido')) { alternarRecolhido(); }", $fechar);
    }

    #[TestDox('depois de um erro do servidor o botão volta a "Criar meta" (não "Criar Tarefa")')]
    public function testRotuloDepoisDoErro(): void
    {
        $show = (string) file_get_contents(self::SHOW);
        // O ícone fica no script e o texto vem do catálogo do idioma, na hora do uso (`pasta_show.js.meta.criar`).
        self::assertStringContainsString("var ICONE_CRIAR = '<i class=\"bi bi-send\" aria-hidden=\"true\"></i>';", $show);
        self::assertSame(2, substr_count($show, "rotular(btn, ICONE_CRIAR, BJ_T.t('pasta_show.js.meta.criar'));"), 'erro do servidor e falha de comunicação');
        self::assertSame('Criar meta', CatalogoPtBr::carregar()['pasta_show']['js']['meta']['criar']);
        self::assertStringNotContainsString('Criar Tarefa', $show);
    }

    private function campoDaEtapa(): string
    {
        $js     = (string) file_get_contents(self::JS_METAS);
        $inicio = strpos($js, 'function regraDaEtapa(raiz) {');
        $fim    = strpos($js, '/* D:4045:');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('Desenho 02 L4049 `nmSug`: a regra do JS vem do data-regra (sem lista própria), sobre título + " " + descrição em minúsculas; vazio não sugere')]
    public function testRegraDaSugestaoVemDoEnum(): void
    {
        $js = $this->campoDaEtapa();
        self::assertStringContainsString("JSON.parse(raiz.getAttribute('data-regra') || '{}')", $js);
        self::assertStringContainsString('new RegExp(r.padrao)', $js);
        self::assertStringContainsString('var t = String(texto || \'\').toLowerCase();', $js);
        self::assertStringContainsString("return t.trim() !== '' ? padrao : '';", $js);
        self::assertStringContainsString("sugerir((titulo ? titulo.value : '') + ' ' + (descricao ? descricao.value : ''))", $js);
        // Fonte única: nenhuma palavra-chave da regra escrita no JS.
        $todoOJs = (string) file_get_contents(self::JS_METAS);
        foreach (EtapaDaPasta::regrasDoTitulo() as $regra) {
            self::assertStringNotContainsString($regra['padrao'], $todoOJs, 'a regra mora no enum, não no JS');
        }
        foreach (['honor', 'ajuiz', 'manifest', 'encerr', 'procura'] as $palavra) {
            self::assertStringNotContainsString($palavra, $todoOJs, 'nem uma palavra-chave solta: ' . $palavra);
        }

        $show = (string) file_get_contents(self::SHOW);
        self::assertStringContainsString('data-regra="{{ regra_da_etapa_pelo_titulo()|json_encode|e(\'html_attr\') }}"', $show);
    }

    #[TestDox('a regra exportada é a do desenho (L4049), na mesma ordem, e o padrão de quem não casa é Jurídico')]
    public function testRegraExportadaEhADoDesenho(): void
    {
        self::assertSame([
            ['padrao' => 'contrato|honor', 'etapa' => 'comercial'],
            ['padrao' => 'cadastro|abertura|relat', 'etapa' => 'abertura'],
            ['padrao' => 'an.lise de pasta|confer|pend', 'etapa' => 'controladoria'],
            ['padrao' => 'document|procura', 'etapa' => 'documentacao'],
            ['padrao' => 'protocol|ajuiz|distribu', 'etapa' => 'protocolo'],
            ['padrao' => 'audi|prazo|publica|manifest', 'etapa' => 'acompanhamento'],
            ['padrao' => 'arquiv|encerr', 'etapa' => 'encerramento'],
        ], EtapaDaPasta::regrasDoTitulo());
        self::assertSame(EtapaDaPasta::Juridico, EtapaDaPasta::deduzirDoTitulo(''));
    }

    /** @return iterable<string, array{string, string}> */
    public static function textosDaSugestao(): iterable
    {
        yield 'contrato' => ['Assinar contrato de honorários', 'comercial'];
        yield 'abertura' => ['Relatório inicial do cliente', 'abertura'];
        yield 'análise de pasta' => ['Análise de pasta nova', 'controladoria'];
        yield 'pendência' => ['Listar pendências', 'controladoria'];
        yield 'procuração' => ['Pedir procuração', 'documentacao'];
        yield 'ajuizar' => ['Ajuizar a ação', 'protocolo'];
        yield 'audiência' => ['Preparar AUDIÊNCIA', 'acompanhamento'];
        yield 'arquivar' => ['Arquivar a pasta', 'encerramento'];
        yield 'nenhuma regra' => ['Elaborar recurso', 'juridico'];
        yield 'primeira vence' => ['Protocolar contrato', 'comercial'];
    }

    /**
     * Os padrões exportados no `data-regra`, aplicados um a um em PHP, batem com o `deduzirDoTitulo` do servidor.
     * Prova a exportação, não o JS: o `regraDaEtapa` não roda aqui (os padrões são só ASCII, `.` e `|`, então a
     * falta da flag `u` no `RegExp` não muda o resultado).
     */
    #[TestDox('os padrões exportados, aplicados em ordem, sugerem o mesmo que o servidor deduz')]
    #[DataProvider('textosDaSugestao')]
    public function testPadroesExportadosSugeremOMesmoQueOServidor(string $texto, string $esperada): void
    {
        $sugerida = 'juridico';
        foreach (EtapaDaPasta::regrasDoTitulo() as $regra) {
            if (preg_match('/' . $regra['padrao'] . '/u', mb_strtolower($texto)) === 1) {
                $sugerida = $regra['etapa'];
                break;
            }
        }

        self::assertSame($esperada, $sugerida);
        self::assertSame($esperada, EtapaDaPasta::deduzirDoTitulo($texto)->value);
    }

    #[TestDox('a sugestão só pinta e mostra a dica; o clique grava no campo enviado e avisa com change; toda abertura limpa a etapa')]
    public function testComportamentoDaEtapa(): void
    {
        $js = $this->campoDaEtapa();
        self::assertStringContainsString("var campo = document.getElementById('tarefaEtapa');", $js);
        self::assertStringContainsString("var sugerida = escolhida === '' ? sugerir(", $js, 'com etapa escolhida, nada é sugerido (a dica some)');
        self::assertStringContainsString("b.setAttribute('aria-pressed', valor === escolhida ? 'true' : 'false');", $js);
        self::assertStringContainsString("b.classList.toggle('is-sugerida', sugerida !== '' && valor === sugerida);", $js);
        self::assertStringContainsString("if (dica) { dica.hidden = nomeSugerido === ''; }", $js);
        self::assertTextoDoScript('Sugestão pelo título: {etapa}', 'metas.sugestao_pelo_titulo', $js);
        self::assertStringContainsString("campo.dispatchEvent(new Event('change', { bubbles: true }));", $js);
        self::assertStringContainsString("el.addEventListener('input', pintar);", $js);
        self::assertStringContainsString("modal.addEventListener('show.bs.modal', function () { escolher(''); });", $js);
        self::assertStringNotContainsString('aria-pressed\') === \'true\' ?', $js, 'não desmarca no segundo clique');
        self::assertStringContainsString('campoDaEtapa(modal, titulo, descricao);', $this->bloco());
    }
}
