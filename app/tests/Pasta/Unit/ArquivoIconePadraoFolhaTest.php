<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Twig\ArquivoIconeExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O padrão de ícone por tipo de arquivo (Guia visual BlueJus, 04 Tipografia e
 * ícones) mora em TRÊS lugares que não se enxergam: o helper Twig
 * `arquivo_icone` (trilho de Dados, Financeiro, Cobrança), o explorador da aba
 * Documentos (pasta-explorador.js `TIPOS`/`TIPO_ALIAS` + tokens `--pex-c-*`) e os
 * tokens `--ps-arquivo-*` do pasta-show.css, que pintam o trilho e o Financeiro por
 * classe. Lido como FOLHA: se um deles mudar sozinho, as duas abas da mesma tela
 * passam a desenhar o mesmo arquivo de jeitos diferentes, e este teste avisa.
 */
#[CoversNothing]
final class ArquivoIconePadraoFolhaTest extends TestCase
{
    private const EXPLORADOR_JS  = __DIR__ . '/../../../public/js/pasta-explorador.js';
    private const EXPLORADOR_CSS = __DIR__ . '/../../../public/css/pasta-explorador.css';
    private const PASTA_CSS      = __DIR__ . '/../../../public/css/pasta-show.css';

    private ArquivoIconeExtension $helper;

    protected function setUp(): void
    {
        $this->helper = new ArquivoIconeExtension();
    }

    private static function ler(string $arquivo): string
    {
        $conteudo = file_get_contents($arquivo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    /** @return array<string, array{0: string, 1: string}> EXT => [ícone, tipo], do `const TIPOS` do explorador */
    private static function tiposDoExplorador(): array
    {
        $js     = self::ler(self::EXPLORADOR_JS);
        $inicio = strpos($js, 'const TIPOS = {');
        self::assertNotFalse($inicio, 'o explorador tem o mapa TIPOS');
        $fim = strpos($js, "\n    };", $inicio);
        self::assertNotFalse($fim);
        $bloco = substr($js, $inicio, $fim - $inicio);

        // O rótulo (3º item) vem do catálogo: `BJ_T.t('pasta_scripts.js.explorador.tipo_*')` (spec idioma-do-sistema §20.9).
        preg_match_all("/^\\s*'?([A-Z0-9]+)'?:\\s*\\['([^']+)',\\s*'([^']+)',\\s*BJ_T\\.t\\('pasta_scripts\\.js\\.explorador\\.tipo_[a-z_]+'\\)\\]/m", $bloco, $m, PREG_SET_ORDER);
        self::assertGreaterThan(30, count($m), 'o mapa TIPOS foi lido');

        $tipos = [];
        foreach ($m as [, $ext, $icone, $tipo]) {
            $tipos[$ext] = [$icone, $tipo];
        }

        return $tipos;
    }

    /** @return array<string, string> ALIAS => EXT base, do `const TIPO_ALIAS` do explorador */
    private static function aliasesDoExplorador(): array
    {
        $js = self::ler(self::EXPLORADOR_JS);
        self::assertSame(1, preg_match('/const TIPO_ALIAS = \{([^}]*)\};/', $js, $m), 'o explorador tem TIPO_ALIAS');
        preg_match_all("/([A-Z0-9]+):\\s*'([A-Z0-9]+)'/", $m[1], $pares, PREG_SET_ORDER);
        self::assertGreaterThan(5, count($pares));

        $aliases = [];
        foreach ($pares as [, $alias, $base]) {
            $aliases[$alias] = $base;
        }

        return $aliases;
    }

    /**
     * Tokens de cor por tipo, tema claro e escuro, de um CSS: o claro é o que vem
     * ANTES do primeiro SELETOR `[data-bs-theme="dark"]` (no início da linha; o
     * texto também aparece em comentários), o escuro o que vem depois.
     *
     * @return array{claro: array<string, string>, escuro: array<string, string>}
     */
    private static function tokens(string $css, string $prefixo): array
    {
        self::assertSame(1, preg_match('/^\[data-bs-theme="dark"\]/m', $css, $m, PREG_OFFSET_CAPTURE), 'o CSS tem tema escuro');
        $corte = $m[0][1];
        $ler = static function (string $trecho) use ($prefixo): array {
            preg_match_all('/' . preg_quote($prefixo, '/') . '([a-z]+):\s*(#[0-9a-f]{6})/', $trecho, $m, PREG_SET_ORDER);
            $tokens = [];
            foreach ($m as [, $tipo, $hex]) {
                $tokens[$tipo] = $hex;
            }

            return $tokens;
        };

        return ['claro' => $ler(substr($css, 0, $corte)), 'escuro' => $ler(substr($css, $corte))];
    }

    #[TestDox('toda extensão que o explorador conhece ganha, no helper, o mesmo ícone e o mesmo tipo')]
    public function testHelperDesenhaCadaExtensaoComoOExplorador(): void
    {
        $tipos = self::tiposDoExplorador();

        foreach ($tipos as $ext => [$icone, $tipo]) {
            $h = $this->helper->arquivoIcone('arquivo.' . strtolower($ext));
            self::assertSame($icone, $h['icone'], "ícone de .{$ext}: helper × explorador");
            self::assertSame($tipo, $h['tipo'], "tipo de .{$ext}: helper × explorador");
        }

        foreach (self::aliasesDoExplorador() as $alias => $base) {
            self::assertArrayHasKey($base, $tipos, "alias {$alias} aponta para {$base}, que o explorador conhece");
            $h = $this->helper->arquivoIcone('arquivo.' . strtolower($alias));
            self::assertSame($tipos[$base][0], $h['icone'], "ícone de .{$alias} (alias de {$base}): helper × explorador");
            self::assertSame($tipos[$base][1], $h['tipo'], "tipo de .{$alias} (alias de {$base}): helper × explorador");
        }
    }

    #[TestDox('as cores por tipo são as mesmas no explorador, no pasta-show.css e no helper, nos dois temas')]
    public function testCoresPorTipoSaoAsMesmasNosTresLugares(): void
    {
        $explorador = self::tokens(self::ler(self::EXPLORADOR_CSS), '--pex-c-');
        $pasta      = self::tokens(self::ler(self::PASTA_CSS), '--ps-arquivo-');

        $tiposDoHelper = [];
        foreach (self::tiposDoExplorador() as $ext => [, $tipo]) {
            $tiposDoHelper[$tipo] = $this->helper->arquivoIcone('arquivo.' . strtolower($ext))['cor'];
        }
        $tiposDoHelper['outro'] = $this->helper->arquivoIcone('arquivo.xyz')['cor'];
        ksort($tiposDoHelper);

        $chaves = array_keys($tiposDoHelper);
        foreach (['explorador claro' => $explorador['claro'], 'explorador escuro' => $explorador['escuro'], 'pasta claro' => $pasta['claro'], 'pasta escuro' => $pasta['escuro']] as $onde => $tokens) {
            ksort($tokens);
            self::assertSame($chaves, array_keys($tokens), "{$onde}: os mesmos tipos que o helper, nem a mais nem a menos");
        }

        foreach ($chaves as $tipo) {
            self::assertSame($explorador['claro'][$tipo], $tiposDoHelper[$tipo], "claro de '{$tipo}': helper × explorador");
            self::assertSame($explorador['claro'][$tipo], $pasta['claro'][$tipo], "claro de '{$tipo}': pasta-show × explorador");
            self::assertSame($explorador['escuro'][$tipo], $pasta['escuro'][$tipo], "escuro de '{$tipo}': pasta-show × explorador");
        }
    }

    #[TestDox('PDF, Word e Excel levam exatamente as cores do Guia visual')]
    public function testCoresDoGuiaVisual(): void
    {
        self::assertSame('#d93a2b', $this->helper->arquivoIcone('a.pdf')['cor']);
        self::assertSame('#1f5fbf', $this->helper->arquivoIcone('a.docx')['cor']);
        self::assertSame('#1d7a46', $this->helper->arquivoIcone('a.xlsx')['cor']);
    }

    #[TestDox('cada tipo tem a classe que pinta: .ps-doc-icone--<tipo> na pasta e .pex-ico-<tipo> no explorador')]
    public function testCadaTipoTemAClasseQuePinta(): void
    {
        $pastaCss      = self::ler(self::PASTA_CSS);
        $exploradorCss = self::ler(self::EXPLORADOR_CSS);

        foreach (array_keys(self::tokens($pastaCss, '--ps-arquivo-')['claro']) as $tipo) {
            self::assertMatchesRegularExpression(
                '/\.ps-doc-icone--' . $tipo . '\s*\{\s*color:\s*var\(--ps-arquivo-' . $tipo . '\);\s*\}/',
                $pastaCss,
                ".ps-doc-icone--{$tipo} lê o token do seu tipo"
            );
            self::assertMatchesRegularExpression(
                '/\.pex-ico-' . $tipo . '\s*\{\s*color:\s*var\(--pex-c-' . $tipo . '\);\s*\}/',
                $exploradorCss,
                ".pex-ico-{$tipo} existe no explorador com a mesma chave de tipo"
            );
        }
    }
}
