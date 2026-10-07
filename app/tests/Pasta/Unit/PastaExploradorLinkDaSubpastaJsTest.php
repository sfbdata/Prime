<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * DOC-120 — "Copiar link" também no menu de uma PASTA (dc l.4813): `pasta_show?pex=<secaoId>#documentos`,
 * e no carregamento o explorador entra nesse nível se — e só se — a seção está no `#pexDados`
 * (as seções vivas desta pasta). Seção de outra pasta, de outro escritório ou valor que não é id
 * é ignorado em silêncio. Teste de FOLHA (sem harness de navegador na suíte).
 */
final class PastaExploradorLinkDaSubpastaJsTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const TPL = __DIR__ . '/../../../templates/pasta/_documentos_explorador.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    private function funcao(string $nome): string
    {
        $js  = $this->js();
        $ini = strpos($js, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($js, "\n    }", $ini);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('o menu de UMA pasta tem "Copiar link" logo depois de "Baixar como .zip"; é leitura (não sai no modo só leitura)')]
    public function testMenuDaPasta(): void
    {
        $menu = $this->funcao('opcoesDoMenu');
        $zip  = strpos($menu, "op('Baixar como .zip', 'bi-download', function () { baixarZip([alvo]); }),");
        $link = strpos($menu, "op('Copiar link', 'bi-link-45deg', function () { copiarLinks([alvo]); }),", (int) $zip);
        self::assertNotFalse($zip);
        self::assertNotFalse($link);
        self::assertLessThan(strpos($menu, '] : [', (int) $zip), $link, 'no ramo da pasta (ehPasta ? [ … ] : [ … ])');
        self::assertStringNotContainsString("'Copiar link'", substr($this->js(), (int) strpos($this->js(), 'const ROTULOS_DE_ESCRITA'), 200));
    }

    #[TestDox('o link da subpasta: a pasta_show desta pasta (data-url-pasta), sem outra query, com ?pex=<id> e #documentos; o arquivo segue com o link de visualização')]
    public function testLink(): void
    {
        $link = $this->funcao('linkDaSubpasta');
        self::assertStringContainsString('const u = new URL(cfg.urlPasta || window.location.pathname, window.location.href);', $link);
        self::assertStringContainsString("u.search = '';", $link);
        self::assertStringContainsString("u.searchParams.set('pex', String(Number(id)));", $link);
        self::assertStringContainsString("u.hash = 'documentos';", $link);
        self::assertStringContainsString('return u.href;', $link, 'absoluto, como o do arquivo');

        self::assertStringContainsString("urlPasta:            raiz.dataset.urlPasta || '',", $this->js());
        self::assertStringContainsString('data-url-pasta="{{ path(\'pasta_show\', {id: pasta.id}) }}"', (string) file_get_contents(self::TPL));

        $copiar = $this->funcao('copiarLinks');
        self::assertStringContainsString('arqs.map(function (it) { return linkAbsoluto(it.dado); })', $copiar);
        self::assertStringContainsString('.concat(itens.filter(ehPastaItem).map(function (it) { return linkDaSubpasta(it.id); }));', $copiar);
        self::assertStringContainsString("toast(links.length === 1 ? 'Link copiado' : 'Links copiados')", $copiar);
    }

    #[TestDox('?pex: só id inteiro positivo e só se a seção está em `pastas` (o #pexDados); senão NULL em silêncio. O parâmetro sai da barra de endereço')]
    public function testLeituraDoParametro(): void
    {
        $pex = $this->funcao('pexDoLink');
        self::assertStringContainsString("valor = url.searchParams.get('pex');", $pex);
        self::assertStringContainsString("if (!valor || !/^[1-9]\\d{0,17}$/.test(valor)) return null;", $pex);
        self::assertStringContainsString('return pastaPorId(valor) ? Number(valor) : null;', $pex, 'seção de fora não está em `pastas`');
        self::assertStringContainsString("url.searchParams.delete('pex');", $pex);
        self::assertStringContainsString("window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);", $pex, 'o #documentos fica');
        self::assertStringContainsString('} catch (e) { return null; }', $pex);
        self::assertStringNotContainsString('toast', $pex, 'ignorado em silêncio, sem erro');
    }

    #[TestDox('no carregamento, o nível do link vence o salvo na sessão e passa a ser o salvo; sem link válido, nada muda')]
    public function testInicializar(): void
    {
        $js     = $this->js();
        $ini    = strpos($js, '(function inicializar() {');
        self::assertNotFalse($ini);
        $corpo  = substr($js, $ini);
        $salvo  = strpos($corpo, 'caminho = cadeiaAte(salvo[salvo.length - 1]);');
        $doLink = strpos($corpo, 'const doLink = pexDoLink();');
        self::assertNotFalse($salvo);
        self::assertNotFalse($doLink);
        self::assertLessThan($doLink, $salvo, 'o link é conferido DEPOIS do salvo: vence ele');
        self::assertStringContainsString("if (doLink != null) {\n            caminho = cadeiaAte(doLink);\n            gravarCaminho();\n        }", $corpo);
        self::assertLessThan(strpos($corpo, 'renderizar();'), $doLink, 'antes do primeiro render');
    }
}
