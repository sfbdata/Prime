<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    #[TestDox('D-META: cobra título, descrição, prazo e responsável, nessa ordem e com "responsável" no singular, como o desenho e o servidor')]
    public function testCobraOsQuatroNaOrdemDoDesenho(): void
    {
        $bloco  = $this->bloco();
        $inicio = (int) strpos($bloco, 'function faltaNaMeta(');
        $funcao = substr($bloco, $inicio, (int) strpos($bloco, 'return falta;', $inicio) - $inicio);
        self::assertStringContainsString('function faltaNaMeta(titulo, descricao, prazo, responsaveis)', $funcao);
        $linhas = [
            "titulo.value.trim() === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_titulo')); }",
            "descricao.value.trim() === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_descricao')); }",
            "prazo.value === '') { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_prazo')); }",
            "responsaveis.selectedOptions.length === 0) { falta.push(BJ_T.t('pasta_scripts.js.metas.falta_responsavel')); }",
        ];
        $posicoes = array_map(static fn (string $l): int|false => strpos($funcao, $l), $linhas);
        self::assertNotContains(false, $posicoes, 'as quatro cobranças');
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes, 'título, descrição, prazo, responsável (D:4048 `falta`)');
        self::assertTextoDoScript('título', 'metas.falta_titulo', $funcao);
        self::assertTextoDoScript('descrição', 'metas.falta_descricao', $funcao);
        self::assertTextoDoScript('prazo', 'metas.falta_prazo', $funcao);
        self::assertTextoDoScript('responsável', 'metas.falta_responsavel', $funcao);
        self::assertSame(4, substr_count($funcao, 'falta.push('), 'nada além dos quatro');
    }

    #[TestDox('o calendário, os atalhos e os chips disparam change no campo enviado: a conta refaz neles também')]
    public function testRefazNoCalendarioENosChips(): void
    {
        $bloco = $this->bloco();
        self::assertStringContainsString("var prazo = document.getElementById('tarefaPrazo');", $bloco);
        self::assertStringContainsString("var responsaveis = document.getElementById('tarefaResponsavel');", $bloco);
        self::assertStringContainsString('[titulo, descricao, prazo, responsaveis].forEach(function (el) {', $bloco);
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
        self::assertSame('Criar meta', Yaml::parseFile(__DIR__ . '/../../../translations/messages+intl-icu.pt_BR.yaml')['pasta_show']['js']['meta']['criar']);
        self::assertStringNotContainsString('Criar Tarefa', $show);
    }
}
