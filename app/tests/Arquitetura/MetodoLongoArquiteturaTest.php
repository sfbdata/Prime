<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Método em app/src tem no máximo 40 linhas (do `function` ao `}`; docblock e atributos não contam).
 * A meta das skills `criar-*` é ~20; 40 é só onde o CI reprova.
 *
 * O legado é uma catraca, como a de travessão: `metodos-longos-tolerados.json` guarda, por
 * `arquivo::metodo`, quantas linhas o método tinha quando a regra nasceu (18/10/2026). Ele pode
 * crescer até FOLGA linhas sobre esse número (uma guarda, um log), e a folga não se renova: depois
 * dela, o método só cresce se encolher antes. Encolher nunca quebra nada.
 *
 * Mover um método legado para o domínio (sem reescrever, regra de app/src/CLAUDE.md) é trocar a
 * chave no JSON no mesmo PR, com o MESMO número: o número igual é a prova de que só moveu.
 *
 * Fora do escopo: src/DataFixtures/ (carga de dados declarativa). Closures, arrow functions e
 * métodos abstratos não contam. Dois métodos de mesmo nome no mesmo arquivo (classe anônima) viram
 * `metodo#2`, `metodo#3`.
 */
#[CoversNothing]
final class MetodoLongoArquiteturaTest extends TestCase
{
    public const LIMITE = 40;
    public const FOLGA = 5;

    private const SRC = __DIR__ . '/../../src';
    private const TOLERADOS = __DIR__ . '/metodos-longos-tolerados.json';

    #[TestDox('Nenhum método novo passa de 40 linhas e nenhum legado cresce além da folga')]
    public function testNenhumMetodoPassaDoLimite(): void
    {
        $tolerados = json_decode((string) file_get_contents(self::TOLERADOS), true, flags: JSON_THROW_ON_ERROR);
        $excessos = [];

        foreach (self::medir(self::SRC) as $chave => $linhas) {
            $limite = isset($tolerados[$chave]) ? $tolerados[$chave] + self::FOLGA : self::LIMITE;
            if ($linhas > $limite) {
                $excessos[] = sprintf('%s: %d linhas, o limite é %d%s', $chave, $linhas, $limite, self::dicaDeMovido($chave, $linhas, $tolerados));
            }
        }

        self::assertSame(
            [],
            $excessos,
            'Método longo demais. Extraia o código NOVO para um método privado com nome (não reescreva o '
            . 'legado sem liberação). Não aumente números em metodos-longos-tolerados.json.',
        );
    }

    #[TestDox('Toda chave tolerada ainda existe em src/ (método movido troca a chave; apagado sai da lista)')]
    public function testNenhumaChaveToleradaOrfa(): void
    {
        $tolerados = json_decode((string) file_get_contents(self::TOLERADOS), true, flags: JSON_THROW_ON_ERROR);
        $orfas = array_keys(array_diff_key($tolerados, self::medir(self::SRC)));

        self::assertSame(
            [],
            $orfas,
            'Chave tolerada sem método em src/. Se o método foi movido, troque a chave mantendo o número; se '
            . 'foi apagado ou ficou curto, rode o comando de baixar do docblock de medir(). Vaga aberta '
            . 'deixaria um método novo de mesmo nome herdar a tolerância.',
        );
    }

    #[TestDox('Baixar a lista nunca sobe número, nunca cria chave e tira o que ficou curto ou sumiu')]
    public function testBaixarNuncaSobe(): void
    {
        self::assertSame(
            ['a.php::cresceu' => 50, 'a.php::encolheu' => 45, 'a.php::igual' => 60],
            self::baixar(
                ['a.php::encolheu' => 50, 'a.php::cresceu' => 50, 'a.php::igual' => 60, 'a.php::ficouCurto' => 50, 'a.php::sumiu' => 70],
                ['a.php::encolheu' => 45, 'a.php::cresceu' => 54, 'a.php::igual' => 60, 'a.php::ficouCurto' => 30, 'a.php::novo' => 90],
            ),
            'encolheu baixa; cresceu mantém o número antigo (a folga não renova); curto e sumido saem; novo não entra',
        );
    }

    #[TestDox('O medidor conta do function ao fechamento e ignora closure, arrow function e abstrato')]
    public function testMedidorContaSoMetodoNomeado(): void
    {
        $raiz = sys_get_temp_dir() . '/metodo-longo-' . bin2hex(random_bytes(4));
        mkdir($raiz . '/Dominio', 0777, true);
        mkdir($raiz . '/DataFixtures', 0777, true);

        $corpo = static fn (int $n): string => str_repeat("        \$x = 1;\n", $n);
        file_put_contents($raiz . '/Dominio/A.php', "<?php\nabstract class A\n{\n"
            . "    /** doc */\n    #[Atributo]\n    public function curto(): void\n    {\n" . $corpo(3) . "    }\n\n"
            . "    public function &referencia(): array\n    {\n        \$f = function () {\n" . $corpo(2) . "        };\n"
            . "        \$g = fn () => 1;\n        return [];\n    }\n\n"
            . "    abstract public function abstrato(): void;\n\n"
            . "    public function __construct(private int \$y) {}\n\n"
            . "    public function list(): string\n    {\n        return \"{\$this->x}\";\n    }\n\n"
            . "    public function anonima(): object\n    {\n        return new class {\n"
            . "            public function curto(): void\n            {\n            }\n        };\n    }\n}\n");
        file_put_contents($raiz . '/DataFixtures/F.php', "<?php\nfunction carga(): void\n{\n" . $corpo(60) . "}\n");

        try {
            $medidas = self::medir($raiz);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($raiz);
        }

        self::assertSame(
            [
                'Dominio/A.php::__construct' => 1,
                'Dominio/A.php::anonima' => 8,
                'Dominio/A.php::curto' => 6,
                'Dominio/A.php::curto#2' => 3,
                'Dominio/A.php::list' => 4,
                'Dominio/A.php::referencia' => 9,
            ],
            $medidas,
            'conta do function ao } de cada método nomeado; closure, fn, abstrato e DataFixtures ficam fora',
        );
    }

    /**
     * Linhas de cada método nomeado, por `arquivo::metodo` relativo a src/.
     *
     * Baixar metodos-longos-tolerados.json depois de encolher ou apagar métodos (rodar de app/). O
     * comando passa por baixar(): nunca sobe número nem cria chave. Método movido: troque a chave à mão.
     *   php -r 'require "vendor/autoload.php"; use App\Tests\Arquitetura\MetodoLongoArquiteturaTest as T;
     *     $j = "tests/Arquitetura/metodos-longos-tolerados.json"; file_put_contents($j, json_encode(
     *     T::baixar(json_decode(file_get_contents($j), true), T::medir("src")), JSON_PRETTY_PRINT
     *     | JSON_UNESCAPED_SLASHES) . "\n");'
     *
     * @return array<string, int>
     */
    public static function medir(string $raiz): array
    {
        $resultado = [];
        $arquivos = (new Finder())->files()->in($raiz)->name('*.php')
            ->notPath('#^DataFixtures/#')->sortByName();

        foreach ($arquivos as $arquivo) {
            $relativo = str_replace('\\', '/', $arquivo->getRelativePathname());
            foreach (self::metodosDoCodigo($arquivo->getContents()) as [$nome, $linhas]) {
                $chave = $relativo . '::' . $nome;
                for ($n = 2; isset($resultado[$chave]); ++$n) {
                    $chave = $relativo . '::' . $nome . '#' . $n;
                }
                $resultado[$chave] = $linhas;
            }
        }

        ksort($resultado);

        return $resultado;
    }

    /**
     * A lista nova a partir da atual: cada número fica no menor entre o tolerado e o medido; sai o que
     * sumiu de src/ ou ficou dentro do limite; nada novo entra.
     *
     * @param array<string, int> $tolerados
     * @param array<string, int> $medidas
     *
     * @return array<string, int>
     */
    public static function baixar(array $tolerados, array $medidas): array
    {
        $baixados = [];
        foreach ($tolerados as $chave => $numero) {
            $atual = $medidas[$chave] ?? null;
            if ($atual !== null && $atual > self::LIMITE) {
                $baixados[$chave] = min($numero, $atual);
            }
        }

        ksort($baixados);

        return $baixados;
    }

    /** @return list<array{string, int}> nome e linhas de cada método nomeado, na ordem do arquivo */
    private static function metodosDoCodigo(string $codigo): array
    {
        $tokens = token_get_all($codigo);
        $metodos = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nome = self::nomeDepoisDeFunction($tokens, $i);
            $abertura = $nome === null ? null : self::aberturaDoCorpo($tokens, $i);
            if ($abertura === null) {
                continue;
            }

            $metodos[] = [$nome, self::linhaDoFechamento($tokens, $abertura, $token[2]) - $token[2] + 1];
        }

        return $metodos;
    }

    /** Nome do método, ou null quando é closure (`function (` / `function &(`). */
    private static function nomeDepoisDeFunction(array $tokens, int $i): ?string
    {
        for ($j = $i + 1; isset($tokens[$j]); ++$j) {
            $t = $tokens[$j];
            // `&` de retorno por referência vem como token próprio desde o PHP 8.1.
            if (is_array($t) && $t[0] === T_WHITESPACE || (is_array($t) ? $t[1] : $t) === '&') {
                continue;
            }

            return is_array($t) && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $t[1]) === 1 ? $t[1] : null;
        }

        return null;
    }

    /** Índice do `{` do corpo, ou null quando o método é abstrato/de interface (termina em `;`). */
    private static function aberturaDoCorpo(array $tokens, int $i): ?int
    {
        for ($j = $i + 1; isset($tokens[$j]); ++$j) {
            if ($tokens[$j] === '{') {
                return $j;
            }
            if ($tokens[$j] === ';') {
                return null;
            }
        }

        return null;
    }

    private static function linhaDoFechamento(array $tokens, int $abertura, int $linhaDoFunction): int
    {
        $profundidade = 0;
        $linha = $linhaDoFunction;
        for ($j = $abertura - 1; $j > 0 && !is_array($tokens[$j]); --$j) {
        }
        if (is_array($tokens[$j])) {
            $linha = $tokens[$j][2] + substr_count($tokens[$j][1], "\n");
        }

        for ($j = $abertura; isset($tokens[$j]); ++$j) {
            $t = $tokens[$j];
            if (is_array($t)) {
                $linha = $t[2] + substr_count($t[1], "\n");
                $profundidade += in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) ? 1 : 0;
                continue;
            }
            if ($t === '{') {
                ++$profundidade;
            }
            if ($t === '}' && --$profundidade === 0) {
                return $linha;
            }
        }

        return $linha;
    }

    /** Se o método parece ter só mudado de lugar, aponta a chave antiga para trocar no JSON. */
    private static function dicaDeMovido(string $chave, int $linhas, array $tolerados): string
    {
        $nome = substr($chave, strrpos($chave, '::') + 2);
        $atuais = self::medir(self::SRC);

        foreach ($tolerados as $antiga => $numero) {
            if ($numero === $linhas && !isset($atuais[$antiga]) && str_ends_with($antiga, '::' . $nome)) {
                return sprintf("\n    Parece movido de %s: troque a chave no JSON, mantendo %d.", $antiga, $numero);
            }
        }

        return '';
    }
}
