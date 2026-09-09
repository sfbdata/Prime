<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O drawer do histórico da pasta abria SEM FUNDO, com o conteúdo da página
 * aparecendo por baixo do texto.
 *
 * A causa não estava no drawer: os tokens `--ps-*` do redesenho eram declarados
 * só em `.ps-page`, e o drawer vive de propósito FORA dela — é `position: fixed`,
 * e um ancestral com `transform` (a animação dos painéis) viraria o bloco de
 * contenção dele, o mesmo defeito que prendeu os modais do gerenciador abaixo do
 * backdrop. Custom property herda pela árvore do DOM: fora de `.ps-page` todo
 * `var(--ps-card)` ficava sem valor, a declaração virava inválida e `background`
 * caía para o inicial (transparente). Onze tokens sumiam de uma vez, incluindo
 * todas as cores de texto do painel.
 *
 * Aqui se confere a FOLHA, não a tela: onde o drawer mora no HTML é o
 * `PastaDadosArranjoTelaTest` que trava. Este é o outro lado do par, e é o lado
 * que nenhum assert de HTML consegue ver.
 *
 * A lista de classes a conferir sai do PRÓPRIO partial do drawer: classe nova lá
 * entra aqui sozinha, sem ninguém lembrar de atualizar o teste.
 */
final class PastaShowTokensDoDrawerTest extends TestCase
{
    private const CAMINHO_CSS    = __DIR__ . '/../../../public/css/pasta-show.css';
    private const CAMINHO_DRAWER = __DIR__ . '/../../../templates/pasta/_historico_drawer.html.twig';

    /** Seletores que alcançam o drawer, que é filho do <body>. */
    private const RAIZES       = ['.ps-body', 'body.ps-body'];
    private const PREFIXO_DARK = '[data-bs-theme="dark"]';

    /**
     * Os ÚNICOS tokens que não mudam com o tema. `.ps-body` casa nos dois temas e
     * `[data-bs-theme="dark"] .ps-body` só redefine o que muda, então curva de
     * animação fica declarada uma vez, no claro, e segue valendo no escuro.
     *
     * A lista é a exceção de propósito: token novo é cobrado no escuro por
     * padrão. Deduzir "isto é cor" pelo formato do valor deixaria passar
     * `white`, `hsl(...)` ou `var(--bs-*)` — justo o que este teste protege.
     */
    private const TOKENS_SEM_TEMA = ['--ps-ease', '--ps-spring'];

    #[TestDox('todo token --ps-* usado pelo drawer é declarado num escopo que alcança o <body>')]
    public function testTokensDoDrawerSaoDeclaradosNoEscopoDoBody(): void
    {
        $css    = $this->cssSemComentarios();
        $usados = $this->tokensUsadosPelasRegrasDoDrawer($css);

        self::assertNotEmpty(
            $usados,
            'nenhuma regra do drawer foi encontrada no CSS: o teste perdeu o alvo e pararia de proteger qualquer coisa'
        );

        $faltando = array_diff(array_keys($usados), array_keys($this->tokensDoEscopoDoBody($css, escuro: false)));

        self::assertSame(
            [],
            array_values($faltando),
            'o drawer usa token que nenhum escopo alcançando o <body> declara. A declaração vira inválida e a '
            . 'propriedade cai para o valor inicial: fundo transparente e texto na cor herdada. Faltando: '
            . $this->descrever($faltando, $usados)
        );
    }

    #[TestDox('todo token de tema que o drawer usa é redefinido no escuro')]
    public function testTokensDeTemaDoDrawerTemVersaoEscura(): void
    {
        $css    = $this->cssSemComentarios();
        $usados = $this->tokensUsadosPelasRegrasDoDrawer($css);

        self::assertNotEmpty($usados, 'nenhuma regra do drawer foi encontrada no CSS');

        $escuro          = $this->tokensDoEscopoDoBody($css, escuro: true);
        $semVersaoEscura = [];

        foreach (array_keys($usados) as $token) {
            if (in_array($token, self::TOKENS_SEM_TEMA, true)) {
                continue;
            }
            if (!isset($escuro[$token])) {
                $semVersaoEscura[] = $token;
            }
        }

        self::assertSame(
            [],
            $semVersaoEscura,
            'no tema escuro estes tokens ficariam com o valor do tema CLARO dentro do drawer, que é como '
            . 'texto escuro sobre fundo escuro chega na tela. Se algum for mesmo neutro, ele entra em '
            . 'TOKENS_SEM_TEMA — com justificativa. Sem versão escura: '
            . $this->descrever($semVersaoEscura, $usados)
        );
    }

    #[TestDox('a cor de link do redesenho alcança o drawer, e continua de fora das âncoras que são botão')]
    public function testRegraDeCorDeLinkAlcancaODrawer(): void
    {
        $css    = $this->cssSemComentarios();
        $partes = [];

        foreach ($this->blocos($css) as [$seletor, $corpo]) {
            if (!str_contains($corpo, 'color:')) {
                continue;
            }

            foreach ($this->partesDoSeletor($seletor) as $parte) {
                // Âncora descendente do drawer: `.ps-drawer a…`.
                if (preg_match('/^\.ps-drawer\b.*\sa(?![a-z0-9-])/', $parte) === 1) {
                    $partes[] = $parte;
                }
            }
        }

        self::assertNotEmpty(
            $partes,
            'a regra de cor de link é escopada em `.ps-page`, que NÃO alcança o drawer: o link da tarefa dentro '
            . 'do histórico volta para a cor de link padrão do app, divergindo do resto da tela'
        );

        foreach ($partes as $parte) {
            self::assertStringContainsString(
                ':not(.btn)',
                $parte,
                'âncora que é BOTÃO tem de ficar de fora ("' . $parte . '"): a regra vence `.btn-primary` na '
                . 'especificidade e pinta texto azul de link sobre o fundo azul do próprio botão — foi o que '
                . 'aconteceu com "Peticionar na Pasta" e com "Importar relatório" na carteira'
            );
        }
    }

    // =========================================================================
    // Leitura do CSS
    // =========================================================================

    private function cssSemComentarios(): string
    {
        $css = file_get_contents(self::CAMINHO_CSS);
        self::assertIsString($css, 'pasta-show.css não foi encontrado em ' . self::CAMINHO_CSS);

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Blocos `seletor { corpo }`. O padrão casa o bloco mais interno, então regra
     * dentro de @media entra normalmente e o `@media (...)` fica de fora.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function blocos(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

        return array_map(static fn (array $b): array => [trim($b[1]), $b[2]], $m);
    }

    /** @return list<string> */
    private function partesDoSeletor(string $seletor): array
    {
        $partes = [];
        foreach (explode(',', $seletor) as $parte) {
            $partes[] = (string) preg_replace('/\s+/', ' ', trim($parte));
        }

        return $partes;
    }

    /**
     * Classes `ps-*` que o partial do drawer realmente usa. Sai do template para
     * o teste não envelhecer sozinho.
     *
     * @return list<string>
     */
    private function classesDoDrawer(): array
    {
        $twig = file_get_contents(self::CAMINHO_DRAWER);
        self::assertIsString($twig, 'o partial do drawer não foi encontrado em ' . self::CAMINHO_DRAWER);

        // Comentário Twig fora: menção a uma classe em prosa não é uso.
        $marcacao = (string) preg_replace('/\{#.*?#\}/s', '', $twig);

        preg_match_all('/\bps-[a-z0-9-]+/', $marcacao, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Tokens consumidos por regras que atingem alguma classe do drawer.
     *
     * @return array<string, string> token => seletor que o usa (para a mensagem de falha)
     */
    private function tokensUsadosPelasRegrasDoDrawer(string $css): array
    {
        $classes = $this->classesDoDrawer();
        $usados  = [];

        foreach ($this->blocos($css) as [$seletor, $corpo]) {
            if (!$this->seletorAtingeAlgumaClasse($seletor, $classes)) {
                continue;
            }

            preg_match_all('/var\(\s*(--ps-[a-z0-9-]+)/', $corpo, $m);
            foreach ($m[1] as $token) {
                $usados[$token] ??= $seletor;
            }
        }

        return $usados;
    }

    /**
     * @param list<string> $classes
     */
    private function seletorAtingeAlgumaClasse(string $seletor, array $classes): bool
    {
        foreach ($classes as $classe) {
            // Fronteira à direita: `.ps-drawer` não pode casar dentro de
            // `.ps-drawer-cab` por acidente — cada classe é conferida por si.
            if (preg_match('/\.' . preg_quote($classe, '/') . '(?![a-z0-9-])/', $seletor) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokens declarados em bloco cujo seletor alcança o `<body class="ps-body">`.
     * O `;` final é opcional: a última declaração de um bloco pode não ter.
     *
     * @return array<string, string> token => valor declarado
     */
    private function tokensDoEscopoDoBody(string $css, bool $escuro): array
    {
        $declarados = [];

        foreach ($this->blocos($css) as [$seletor, $corpo]) {
            if (!$this->seletorAlcancaOBody($seletor, $escuro)) {
                continue;
            }

            preg_match_all('/(--ps-[a-z0-9-]+)\s*:\s*([^;]*)/', $corpo, $m, PREG_SET_ORDER);
            foreach ($m as $decl) {
                $declarados[$decl[1]] = trim($decl[2]);
            }
        }

        return $declarados;
    }

    private function seletorAlcancaOBody(string $seletor, bool $escuro): bool
    {
        foreach ($this->partesDoSeletor($seletor) as $parte) {
            foreach (self::RAIZES as $raiz) {
                $alvo = $escuro ? self::PREFIXO_DARK . ' ' . $raiz : $raiz;
                if ($parte === $alvo) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int|string, string> $faltando
     * @param array<string, string>     $usados
     */
    private function descrever(array $faltando, array $usados): string
    {
        $linhas = [];
        foreach ($faltando as $token) {
            $linhas[] = $token . ' (usado por "' . ($usados[$token] ?? '?') . '")';
        }

        return implode(', ', $linhas);
    }
}
