<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

/**
 * A Reforçada nas telas, lida no texto dos scripts (o container não tem node; contrato da etapa 7, seções 7 e 11).
 *
 * Arranjo: o teste monta o esqueleto HTML que o `formulario()` da tela pública devolve (só os textos literais do nível
 * de fora da concatenação, com o `reforco()` posto no lugar da chamada) e confere por filho direto onde o desenho põe
 * os elementos novos (linha 251): soltos no painel, depois da linha do código e antes do aceite.
 */
final class TelaReforcadaJsTest extends TestCase
{
    private const PUBLICA = __DIR__ . '/../../../public/js/assinatura-publica.js';
    private const CSS_PUBLICA = __DIR__ . '/../../../public/css/assinatura-publica.css';
    private const INTERNA = __DIR__ . '/../../../public/js/assinatura.js';
    private const TEXTOS_PUBLICA = __DIR__ . '/../../../translations/assinatura_eletronica_publica/messages+intl-icu.pt_BR.yaml';
    private const TEXTOS_TELA = __DIR__ . '/../../../translations/assinatura_eletronica_tela/messages+intl-icu.pt_BR.yaml';

    #[TestDox('arranjo: aviso, botão da selfie e localização são filhos diretos do painel, entre a linha do código e o aceite')]
    public function testArranjoDoPainel(): void
    {
        $js = self::ler(self::PUBLICA);
        $html = new Crawler('<div id="raiz">' . self::esqueleto($js, 'formulario', ['reforco' => self::esqueleto($js, 'reforco', [])]) . '</div>');

        self::assertCount(1, $html->filter('.bjaPainel > .bjaCodLin + .bjaSelfieAviso + label.bjaB.bjaN.bjaSelfie + .bjaGeo + label.bjaAceite'));
        self::assertCount(1, $html->filter('.bjaPainel > label.bjaSelfie > i.bi.bi-camera + span[data-bja-selfie-rotulo] + input[type="file"][accept="image/*"][capture="user"][data-bja-selfie]'));
        self::assertCount(1, $html->filter('.bjaPainel > div.bjaGeo[data-bja-geo]'));
        self::assertStringContainsString("if (!est.reforcada) { return ''; }", self::corpo($js, 'reforco'), 'só quem é Reforçada vê o bloco');
    }

    #[TestDox('estilos do desenho: botão de 38 px com o campo escondido, as duas linhas em 12 px #5b7383 e o desabilitado da prévia')]
    public function testEstilos(): void
    {
        $css = self::ler(self::CSS_PUBLICA);

        self::assertStringContainsString('.bjaPub .bjaSelfie { height: 38px; cursor: pointer; }', $css);
        self::assertStringContainsString('.bjaPub .bjaSelfie > input { display: none; }', $css);
        self::assertStringContainsString('.bjaPub .bjaSelfie[aria-disabled="true"] { opacity: .5; cursor: default; }', $css);
        self::assertStringContainsString('.bjaPub .bjaSelfieAviso, .bjaPub .bjaGeo { font-size: 12px; color: #5b7383; }', $css);
    }

    #[TestDox('localização como o desenho (timeout de 6 s, 5 casas) e a selfie reduzida no navegador (1600 px, JPEG 0,85)')]
    public function testLocalizacaoESelfie(): void
    {
        $js = self::ler(self::PUBLICA);

        self::assertStringContainsString('navigator.geolocation.getCurrentPosition(', $js);
        self::assertStringContainsString('{ timeout: 6000 }', $js);
        self::assertStringContainsString('toFixed(5)', self::corpo($js, 'textoGeo'));
        $leitura = self::corpo($js, 'lerSelfie');
        self::assertStringContainsString('1600', $leitura);
        self::assertStringContainsString("toDataURL('image/jpeg', 0.85)", $leitura);
        self::assertStringContainsString("fillStyle = '#fff'", $leitura, 'fundo branco antes de desenhar a foto');
        self::assertStringContainsString('desenhar();' . "\n" . '    pedirLocal();', $js, 'a localização é pedida ao abrir');
    }

    #[TestDox('"Assinar": código, selfie, localização e aceite, nessa ordem; o corpo leva selfie e localização só da Reforçada')]
    public function testOrdemDoAssinar(): void
    {
        $assinar = self::corpo(self::ler(self::PUBLICA), 'assinar');
        $posicoes = array_map(static fn (string $erro): int|false => strpos($assinar, "T('erro." . $erro . "')"), ['codigo', 'selfie', 'localizacao', 'aceite']);

        self::assertNotContains(false, $posicoes);
        $ordenadas = $posicoes;
        sort($ordenadas);
        self::assertSame($ordenadas, $posicoes);
        self::assertStringContainsString('if (est.reforcada) { corpo.selfie = a.selfie; corpo.localizacao = a.geo; }', $assinar);
    }

    #[TestDox('textos do desenho na tela pública (B3, B7, B8, D1) e o aviso de LGPD')]
    public function testTextosDaTelaPublica(): void
    {
        $js = (array) (Yaml::parseFile(self::TEXTOS_PUBLICA)['assinatura_eletronica_publica']['js'] ?? []);

        self::assertSame('Enviar selfie com documento', $js['selfie']['enviar'] ?? null);
        self::assertSame('Selfie anexada', $js['selfie']['anexada'] ?? null);
        self::assertSame('Localização: {local}', $js['local']['obtida'] ?? null);
        self::assertSame('Localização não informada nesta pré-visualização.', $js['local']['previa'] ?? null);
        self::assertSame('Envie a selfie com o documento.', $js['erro']['selfie'] ?? null);
        self::assertSame('Permita o acesso à localização para assinar.', $js['erro']['localizacao'] ?? null);
        // Aviso de LGPD fiel ao que o sistema faz: só a selfie é cifrada; a localização fica na trilha; quem vê é a
        // equipe do escritório (o detalhe interno), não só quem enviou. Texto provisório do pacote jurídico (L3).
        $aviso = (string) ($js['selfie']['aviso'] ?? '');
        self::assertSame(
            'A selfie com o documento e a localização servem para comprovar esta assinatura. A selfie fica guardada cifrada; a localização fica registrada como evidência. Quem vê é a equipe do escritório que enviou o documento.',
            $aviso,
        );
        self::assertStringNotContainsString('remetente', $aviso, 'o acesso não é só de quem enviou');
        self::assertStringNotContainsString('cifradas', $aviso, 'a localização não é cifrada');
    }

    #[TestDox('detalhe interno: o método da Reforçada com a localização de 5 casas (D3, F2)')]
    public function testDetalheInterno(): void
    {
        $js = self::ler(self::INTERNA);
        $tela = (array) (Yaml::parseFile(self::TEXTOS_TELA)['assinatura_eletronica_tela']['js'] ?? []);

        self::assertStringContainsString("T('reforcada.metodo', { local: ", $js);
        self::assertStringContainsString("T('reforcada.metodo_sem_local')", $js);
        self::assertStringContainsString('const metodo = metodoRotulo(x);', $js);
        self::assertSame('E-mail e código, selfie, localização {local}', $tela['reforcada']['metodo'] ?? null);
        self::assertSame('E-mail e código, selfie', $tela['reforcada']['metodo_sem_local'] ?? null);
    }

    private static function ler(string $arquivo): string
    {
        $texto = file_get_contents($arquivo);
        self::assertIsString($texto, $arquivo);

        return $texto;
    }

    /** O corpo da `function <nome>(...) { ... }`, sem as chaves de fora (os textos entre aspas não contam chave). */
    private static function corpo(string $js, string $nome): string
    {
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertIsInt($inicio, 'faltou a function ' . $nome);
        $abre = strpos($js, '{', $inicio);
        self::assertIsInt($abre);
        $profundidade = 0;
        for ($i = $abre, $n = \strlen($js); $i < $n; ++$i) {
            $c = $js[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::fimDoTexto($js, $i);
                continue;
            }
            if ($c === '{') {
                ++$profundidade;
            } elseif ($c === '}' && --$profundidade === 0) {
                return substr($js, $abre + 1, $i - $abre - 1);
            }
        }
        self::fail('a function ' . $nome . ' não fecha');
    }

    /**
     * O HTML do último `return` da função: só os textos entre aspas simples fora de parênteses (o que a função sempre
     * devolve), com as chamadas listadas em `$embutir` trocadas pelo esqueleto delas.
     *
     * @param array<string, string> $embutir
     */
    private static function esqueleto(string $js, string $funcao, array $embutir): string
    {
        $corpo = self::corpo($js, $funcao);
        $expressao = substr($corpo, (int) strrpos($corpo, 'return ') + 7);
        $html = '';
        $profundidade = 0;
        for ($i = 0, $n = \strlen($expressao); $i < $n; ++$i) {
            $c = $expressao[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $fim = self::fimDoTexto($expressao, $i);
                if ($profundidade === 0 && $c === "'") {
                    $html .= stripslashes(substr($expressao, $i + 1, $fim - $i - 1));
                }
                $i = $fim;
                continue;
            }
            if ($profundidade === 0 && $c === ';') {
                break;
            }
            if ($profundidade === 0 && preg_match('/\G([A-Za-z_]\w*)\(\)/', $expressao, $m, 0, $i) === 1 && isset($embutir[$m[1]])) {
                $html .= $embutir[$m[1]];
                $i += \strlen($m[0]) - 1;
                continue;
            }
            $profundidade += match ($c) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
        }

        return $html;
    }

    private static function fimDoTexto(string $js, int $abre): int
    {
        $aspa = $js[$abre];
        for ($i = $abre + 1, $n = \strlen($js); $i < $n; ++$i) {
            if ($js[$i] === '\\') {
                ++$i;
            } elseif ($js[$i] === $aspa) {
                return $i;
            }
        }
        self::fail('texto sem fim');
    }
}
