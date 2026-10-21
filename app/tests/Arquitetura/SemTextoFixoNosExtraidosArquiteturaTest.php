<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md, §4 "Guardas de teste"): depois que uma área foi extraída
 * para o catálogo, texto novo nos arquivos dela entra por `|trans` (Twig) ou `BJ_T.t()` (JS), nunca fixo. A lista
 * dos arquivos extraídos, e do que fica como está, mora em `templates-extraidos.json`, um bloco por área.
 *
 * O detector é de propósito simples e conservador. No Twig: texto entre tags e os atributos que a pessoa lê
 * (`title`, `placeholder`, `aria-label`, `alt`, `data-tip`...) fora de `{{ }}`/`{% %}`, e, dentro deles, a string literal que
 * parece frase (começa com maiúscula seguida de minúscula, ou tem acento) e não vai para `|trans`. No JS: a mesma
 * regra de frase para as strings fora de comentário. Marca, sigla e exemplo que ficam como estão vão em `permitidos`. O JS inline
 * (`<script>` no template) fica fora: o que ele ainda tem de fixo está listado na spec (§8).
 */
#[CoversNothing]
final class SemTextoFixoNosExtraidosArquiteturaTest extends TestCase
{
    private const APP = __DIR__ . '/../..';
    private const LISTA = __DIR__ . '/templates-extraidos.json';
    private const ATRIBUTOS_LIDOS = 'title|placeholder|aria-label|alt|data-bs-title|data-title|data-tip|data-confirmar|data-principal|data-suplementar|aria-description|aria-roledescription';
    private const FRASE = '/^(?:[A-ZÀ-Ý][a-zà-ÿ]|.*[À-ÿ])/su';

    #[TestDox('Nenhum arquivo já extraído para o catálogo tem texto fixo de tela')]
    public function testExtraidosSemTextoFixo(): void
    {
        $falhas = [];
        foreach (self::areas() as $area => $bloco) {
            $permitidos = $bloco['permitidos'] ?? [];
            foreach ($bloco['templates'] ?? [] as $arquivo) {
                $achados = self::textosFixosNoTwig(self::ler('templates/' . $arquivo), $permitidos);
                if ($achados !== []) {
                    $falhas[] = sprintf("%s (%s):\n    %s", $arquivo, $area, implode("\n    ", $achados));
                }
            }
            foreach ($bloco['js'] ?? [] as $arquivo) {
                $achados = self::textosFixosNoJs(self::ler('public/js/' . $arquivo), $permitidos);
                if ($achados !== []) {
                    $falhas[] = sprintf("%s (%s):\n    %s", $arquivo, $area, implode("\n    ", $achados));
                }
            }
        }

        self::assertSame([], $falhas, "Texto fixo em arquivo já extraído. Ponha a chave no catálogo (translations/messages+intl-icu.pt_BR.yaml) e use |trans ou BJ_T.t(). Marca ou sigla que fica como está vai em 'permitidos' do templates-extraidos.json.");
    }

    #[TestDox('O detector acha texto, atributo lido e literal de frase, e ignora tradução, comentário, script e marca')]
    public function testDetectorDoTwig(): void
    {
        $twig = <<<'TWIG'
            {# Comentário fora #}
            <div class="mp-x" title="Abrir menu">Olá, {{ nome }}</div>
            <button aria-label="{{ 'menu.fechar'|trans }}">{{ 'menu.sair'|trans }}</button>
            <span>{{ ativo ? 'Ativo' : 'menu.inativo'|trans }}</span>
            <b>{{ 'Salvar alterações'|trans }}</b>
            <i class="bi bi-x"></i> <em>BlueJus</em> <!-- Aviso fora -->
            <script>const x = 'Fora do Twig';</script>
            {% include 'menu/_a.html.twig' with {classe: 'btn btn-primary', titulo: 'Minha conta'} %}
            {% set estiloFolha %}body { font-family: Arial; } .Titulo { color: red; }{% endset %}
            TWIG;

        self::assertSame(
            ['atributo title: Abrir menu', 'texto: Olá,', "literal: 'Ativo'", "literal: 'Minha conta'"],
            self::textosFixosNoTwig($twig, ['BlueJus']),
        );
    }

    #[TestDox('O detector do JS acha a string de frase e ignora seletor, chave e comentário')]
    public function testDetectorDoJs(): void
    {
        $js = "// Comentário fora\n/* Bloco fora */\nel.textContent = 'Salvo com sucesso';\nconst s = document.querySelector('[data-mu] .item');\nt('menu.usuario.js.sair');\nconst m = `Erro ao salvar`;\nconst ok = \"Mudança feita\";";

        self::assertSame(["'Salvo com sucesso'", '`Erro ao salvar`', '"Mudança feita"'], self::textosFixosNoJs($js, []));
    }

    /**
     * @param list<string> $permitidos
     *
     * @return list<string>
     */
    public static function textosFixosNoTwig(string $twig, array $permitidos): array
    {
        // CSS guardado numa variável ({% set estiloFolha %}...{% endset %}, folha que vai à tela e ao PDF) conta como <style>.
        $sem = preg_replace(['/\{#.*?#\}/s', '/<!--.*?-->/s', '/<(script|style|svg)\b.*?<\/\1>/si', '/\{%-?\s*set\s+(?:estilo|css)\w*\s*-?%\}.*?\{%-?\s*endset\s*-?%\}/s'], '', $twig) ?? '';
        $achados = [];
        preg_match_all('/\b(' . self::ATRIBUTOS_LIDOS . ')="([^"]*)"/', $sem, $atributos, PREG_SET_ORDER);
        foreach ($atributos as [, $nome, $valor]) {
            $texto = trim(self::semTwig($valor));
            if (self::temLetra($texto) && !\in_array($texto, $permitidos, true)) {
                $achados[] = 'atributo ' . $nome . ': ' . $texto;
            }
        }
        $semTags = preg_replace('/<[^>]*>/', "\n", $sem) ?? '';
        foreach (preg_split('/\n/', self::semTwig($semTags)) ?: [] as $linha) {
            $texto = trim($linha);
            if (self::temLetra($texto) && !\in_array($texto, $permitidos, true)) {
                $achados[] = 'texto: ' . $texto;
            }
        }

        return [...$achados, ...self::literaisDeFrase($sem, $permitidos)];
    }

    /**
     * @param list<string> $permitidos
     *
     * @return list<string>
     */
    public static function textosFixosNoJs(string $js, array $permitidos): array
    {
        $sem = preg_replace(['~/\*.*?\*/~s', '~(^|[^:\'"`\\\\])//[^\n]*~m'], ['', '$1'], $js) ?? '';
        preg_match_all('/([\'"`])((?:\\\\.|(?!\1).)*)\1/s', $sem, $strings, PREG_SET_ORDER);
        $achados = [];
        foreach ($strings as [$todo, , $conteudo]) {
            if (preg_match(self::FRASE, $conteudo) === 1 && !\in_array($conteudo, $permitidos, true)) {
                $achados[] = $todo;
            }
        }

        return $achados;
    }

    /**
     * @param list<string> $permitidos
     *
     * @return list<string>
     */
    private static function literaisDeFrase(string $twig, array $permitidos): array
    {
        preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $twig, $blocos);
        $achados = [];
        foreach ($blocos[0] as $bloco) {
            preg_match_all("/'((?:\\\\.|[^'])*)'(?!\\s*\\|\\s*trans)/", $bloco, $literais);
            foreach ($literais[1] as $literal) {
                if (preg_match(self::FRASE, $literal) === 1 && !self::caminhoDeTemplate($literal) && !\in_array($literal, $permitidos, true)) {
                    $achados[] = "literal: '" . $literal . "'";
                }
            }
        }

        return $achados;
    }

    private static function semTwig(string $texto): string
    {
        return preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', $texto) ?? '';
    }

    private static function temLetra(string $texto): bool
    {
        return preg_match('/\p{L}/u', html_entity_decode($texto)) === 1 && preg_match('/^&[a-z]+;$/', $texto) !== 1;
    }

    private static function caminhoDeTemplate(string $literal): bool
    {
        return str_ends_with($literal, '.twig');
    }

    private static function ler(string $relativo): string
    {
        $caminho = self::APP . '/' . $relativo;
        self::assertFileExists($caminho, 'arquivo listado em templates-extraidos.json não existe');

        return (string) file_get_contents($caminho);
    }

    /** @return array<string, array{templates?: list<string>, js?: list<string>, permitidos?: list<string>}> */
    private static function areas(): array
    {
        $lista = json_decode((string) file_get_contents(self::LISTA), true, flags: JSON_THROW_ON_ERROR);
        unset($lista['_leia']);

        return $lista;
    }
}
