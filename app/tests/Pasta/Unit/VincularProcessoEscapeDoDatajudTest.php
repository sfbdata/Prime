<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Correção de segurança: o "Buscar no CNJ" da janela de vincular monta as linhas de partes e movimentações
 * com template literal + insertAdjacentHTML, e o texto vem do Datajud (fonte externa). Sem escape, um nome
 * de parte com `"><script>` virava HTML na tela. Agora todo valor passa por `mpEsc`.
 *
 * O container de testes não tem Node: o teste lê o script (como os outros testes de JS do repo), prova que
 * nenhum valor do Datajud entra cru e confere a tabela de escape de `mpEsc` contra o `htmlspecialchars` do
 * PHP (o mesmo resultado para os 5 caracteres).
 */
#[CoversNothing]
final class VincularProcessoEscapeDoDatajudTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function script(): string
    {
        return (string) file_get_contents(self::TEMPLATE);
    }

    #[TestDox('nenhum valor do Datajud entra cru nas linhas: todos passam por mpEsc')]
    public function testTodoValorPassaPeloEscape(): void
    {
        $js = $this->script();

        foreach (['parte.nome', 'parte.documento', 'parte.papel', 'mov.dataMovimentacao', 'mov.descricao', 'mov.tipo', 'mov.orgao'] as $campo) {
            self::assertStringContainsString('value="${mpEsc(' . $campo . ')}"', $js, $campo);
            self::assertStringNotContainsString('${' . $campo . "||''}", $js, $campo . ' cru');
        }
        // Só sobram interpolações seguras: o índice (número) e as comparações do tipo da parte.
        preg_match_all('/\$\{(?!mpEsc\(|idx\}|\(parte\.tipo\|\|\'\'\)===|partesList\.length|movsList\.length)[^}]*\}/', $this->trechoDaBusca($js), $cruas);
        self::assertSame([], $cruas[0], 'interpolação sem escape no "Buscar no CNJ"');
    }

    #[TestDox('mpEsc troca os 5 caracteres como o htmlspecialchars: <script> e aspas viram texto')]
    public function testTabelaDoEscape(): void
    {
        $js = $this->script();
        self::assertSame(1, preg_match("/function mpEsc\\(valor\\) \\{.*?return \\{ ([^}]*) \\}\\[c\\];/s", $js, $m));

        $tabela = [];
        preg_match_all("/'(.)': '([^']+)'|\"(.)\": '([^']+)'/", $m[1], $pares, PREG_SET_ORDER);
        foreach ($pares as $p) {
            $tabela[$p[1] !== '' ? $p[1] : $p[3]] = $p[2] !== '' ? $p[2] : $p[4];
        }
        $comoJs = static fn (string $texto): string => strtr($texto, $tabela);

        $malicioso = '"><script>alert(1)</script><img src=x onerror=\'x\'> & cia';
        self::assertSame(htmlspecialchars($malicioso, ENT_QUOTES | ENT_HTML5), str_replace('&#39;', '&apos;', $comoJs($malicioso)));
        self::assertStringNotContainsString('<script>', $comoJs($malicioso));
        self::assertStringNotContainsString('"', $comoJs($malicioso));
    }

    private function trechoDaBusca(string $js): string
    {
        $inicio = strpos($js, "document.getElementById('mp-btn-datajud').addEventListener");
        self::assertNotFalse($inicio);
        $fim = strpos($js, 'mpAttachRemovePartes();', $inicio);

        return substr($js, $inicio, $fim !== false ? $fim - $inicio : null);
    }
}
