<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * PMC-038 — moldura do modal "Nova meta" (desenho 1.2.3, `salvarSt`/`faltaTxt`/`recolher`/
 * `arrastar`). Sem navegador no PHPUnit: o JS é lido como folha. Prova-se o texto exato do
 * desenho para o botão desabilitado e pronto, que só título e descrição são cobrados (prazo e
 * responsáveis obrigatórios são D-META), que a conta refaz ao abrir (outros scripts preenchem por
 * `value`) e que fechar desfaz recolher e arrasto. O efeito é do smoke do dono.
 */
final class PastaModalNovaMetaMolduraJsTest extends TestCase
{
    private const JS_METAS = __DIR__ . '/../../../public/js/pasta-metas.js';
    private const SHOW     = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function bloco(): string
    {
        $js     = (string) file_get_contents(self::JS_METAS);
        $inicio = strpos($js, "var FALTA_NENHUMA = ");
        self::assertNotFalse($inicio);

        return substr($js, $inicio);
    }

    #[TestDox('roda no carregamento e usa os textos do desenho: "Preencha: …" e "Criar meta e notificar os responsáveis"')]
    public function testTextosDoDesenho(): void
    {
        $js = (string) file_get_contents(self::JS_METAS);
        self::assertMatchesRegularExpression('/function iniciar\(\) \{[^}]*molduraDoModalDeMeta\(\);/', $js);

        $bloco = $this->bloco();
        self::assertStringContainsString("var FALTA_NENHUMA = 'Criar meta e notificar os responsáveis';", $bloco);
        self::assertStringContainsString("botao.title = falta.length > 0 ? 'Preencha: ' + falta.join(', ') : FALTA_NENHUMA;", $bloco);
        self::assertStringContainsString('botao.disabled = falta.length > 0;', $bloco);
    }

    #[TestDox('cobra só título e descrição (a regra atual; prazo e responsáveis são D-META), com trim, como o servidor')]
    public function testSoTituloEDescricao(): void
    {
        $bloco  = $this->bloco();
        $funcao = substr($bloco, (int) strpos($bloco, 'function faltaNaMeta('), 400);
        self::assertStringContainsString("titulo.value.trim() === '') { falta.push('título'); }", $funcao);
        self::assertStringContainsString("descricao.value.trim() === '') { falta.push('descrição'); }", $funcao);
        self::assertSame(2, substr_count($funcao, 'falta.push('), 'nada além dos dois');
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
        self::assertStringContainsString("var dica = rec ? 'Expandir' : 'Recolher (continue vendo a lista)';", $bloco);
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
