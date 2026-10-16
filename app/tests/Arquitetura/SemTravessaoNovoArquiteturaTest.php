<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Regra do dono (15/10/2026): nenhum texto que o usuário vê usa travessão (— ou –).
 *
 * O legado ainda tem centenas de ocorrências, que só saem por varredura autorizada. Por isso este
 * teste é uma catraca: `travessoes-tolerados.json` guarda, por arquivo, quantas linhas com travessão
 * existiam quando a regra nasceu, e o teste falha quando um arquivo passa desse número ou quando
 * aparece um arquivo novo com travessão. Corrigir um arquivo nunca quebra nada; só o número antigo
 * fica folgado, e pode ser baixado depois.
 *
 * O que conta: templates Twig (fora de {# #}, <!-- --> e comentários de <script>/<style>), JS de
 * public/js (fora de comentários) e strings de PHP em src/ (pelo tokenizador, então comentário e
 * docblock não contam). Ficam de fora src/Command/ (saída de terminal, ninguém vê na tela) e os
 * testes. Código que precisa reconhecer travessão digitado pelo usuário deve escrever "\x{2014}"
 * ou "\u{2014}", e não o caractere.
 */
#[CoversNothing]
final class SemTravessaoNovoArquiteturaTest extends TestCase
{
    private const APP = __DIR__ . '/../..';
    private const TOLERADOS = __DIR__ . '/travessoes-tolerados.json';
    private const TRAVESSAO = '/[—–]/u';

    #[TestDox('Nenhum arquivo ganha travessão novo em texto de tela')]
    public function testNenhumArquivoGanhaTravessaoNovo(): void
    {
        $tolerados = json_decode((string) file_get_contents(self::TOLERADOS), true, flags: JSON_THROW_ON_ERROR);
        $excessos = [];

        foreach (self::contar(self::APP) as $arquivo => $linhas) {
            $limite = $tolerados[$arquivo] ?? 0;
            if (count($linhas) > $limite) {
                $excessos[] = sprintf("%s: %d linha(s), o limite é %d\n    %s", $arquivo, count($linhas), $limite, implode("\n    ", $linhas));
            }
        }

        self::assertSame(
            [],
            $excessos,
            "Travessão novo em texto de tela. Reescreva com ponto, dois-pontos, vírgula ou parênteses "
            . "(regra no CLAUDE.md, seção Idioma). Não aumente o número em travessoes-tolerados.json.",
        );
    }

    #[TestDox('A catraca enxerga um travessão novo em template, em JS e em string PHP, e ignora comentário')]
    public function testContadorEnxergaTextoEIgnoraComentario(): void
    {
        $raiz = sys_get_temp_dir() . '/sem-travessao-' . bin2hex(random_bytes(4));
        mkdir($raiz . '/templates', 0777, true);
        mkdir($raiz . '/public/js', 0777, true);
        mkdir($raiz . '/src/Command', 0777, true);

        file_put_contents($raiz . '/templates/a.html.twig', "{# comentário — fora #}\n<!-- fora — também -->\n<p>Salvo — pronto</p>\n<script>// fora — js\nconst x = 'dentro — js';</script>\n<style>/* fora — css */</style>");
        file_put_contents($raiz . '/public/js/b.js', "// fora — linha\n/* fora — bloco */\nalert('dentro — js');");
        file_put_contents($raiz . '/src/C.php', "<?php\n// fora — linha\n/** fora — doc */\n\$m = 'dentro — php';");
        file_put_contents($raiz . '/src/Command/D.php', "<?php\n\$saida = 'terminal — fora';");

        try {
            $contagem = array_map('count', self::contar($raiz));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($raiz);
        }

        self::assertSame(
            ['public/js/b.js' => 1, 'src/C.php' => 1, 'templates/a.html.twig' => 2],
            $contagem,
            'deve contar só texto e string, nunca comentário nem comando de terminal',
        );
    }

    /**
     * Linhas com travessão em texto de tela, por arquivo relativo à raiz do app.
     *
     * Também gera travessoes-tolerados.json. Só para BAIXAR números depois de uma varredura, nunca
     * para aceitar travessão novo (rodar de dentro de app/):
     *   php -r 'require "vendor/autoload.php"; echo json_encode(array_map("count",
     *     App\Tests\Arquitetura\SemTravessaoNovoArquiteturaTest::contar(".")), JSON_PRETTY_PRINT
     *     | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";' > tests/Arquitetura/travessoes-tolerados.json
     *
     * @return array<string, list<string>>
     */
    public static function contar(string $raizDoApp): array
    {
        $resultado = [];

        $fontes = [
            ['templates', '*.twig', self::textoDeTemplate(...)],
            ['public/js', '*.js', self::textoDeJs(...)],
            ['src', '*.php', self::textoDePhp(...)],
        ];

        foreach ($fontes as [$pasta, $padrao, $extrair]) {
            if (!is_dir($raizDoApp . '/' . $pasta)) {
                continue;
            }

            $arquivos = (new Finder())->files()->in($raizDoApp . '/' . $pasta)->name($padrao)
                ->notName('*.min.js')->exclude(['vendor', 'Command']);

            foreach ($arquivos as $arquivo) {
                $linhas = [];
                foreach (explode("\n", $extrair($arquivo->getContents())) as $linha) {
                    if (preg_match(self::TRAVESSAO, $linha)) {
                        $linhas[] = mb_substr(trim($linha), 0, 140);
                    }
                }

                if ($linhas !== []) {
                    $resultado[$pasta . '/' . str_replace('\\', '/', $arquivo->getRelativePathname())] = $linhas;
                }
            }
        }

        ksort($resultado);

        return $resultado;
    }

    /** Apaga comentário trocando por quebras de linha, para o número da linha seguir valendo. */
    private static function apagar(string $texto, string $regex): string
    {
        return (string) preg_replace_callback($regex, static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $texto);
    }

    private static function textoDeJs(string $codigo): string
    {
        $codigo = self::apagar($codigo, '#/\*.*?\*/#s');

        // `//` só abre comentário fora de URL: exige início de linha, espaço, `;{}(,` ou o `>` de `<script>`.
        return (string) preg_replace('#(^|[\s;{}(,>])//[^\n]*#m', '$1', $codigo);
    }

    private static function textoDeTemplate(string $template): string
    {
        $template = self::apagar($template, '/\{#.*?#\}/s');
        $template = self::apagar($template, '/<!--.*?-->/s');

        return (string) preg_replace_callback(
            '#<(script|style)\b.*?</\1>#s',
            static fn (array $m): string => self::textoDeJs($m[0]),
            $template,
        );
    }

    private static function textoDePhp(string $codigo): string
    {
        $saida = '';
        foreach (token_get_all($codigo) as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$tipo, $texto] = $token;
            $saida .= in_array($tipo, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
                ? $texto
                : str_repeat("\n", substr_count($texto, "\n"));
        }

        return $saida;
    }
}
