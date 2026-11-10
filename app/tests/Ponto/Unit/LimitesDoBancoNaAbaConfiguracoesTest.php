<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A grade do limite e do prazo do banco na aba Configurações (E17, P73; desenho 16 L245, L250 e L251), lida na fonte:
 * os valores do CSS do desenho em `public/css/ponto-eletronico.css` e as escolhas do `public/js/ponto-configuracoes.js`
 * de que a tela depende (o PHPUnit não executa script; o smoke no navegador é do dono). O HTML e a rota são provados em
 * LimitesDoBancoDoPontoControllerTest.
 */
#[Group('ponto')]
#[CoversNothing]
final class LimitesDoBancoNaAbaConfiguracoesTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/ponto-eletronico.css';
    private const JS = __DIR__ . '/../../../public/js/ponto-configuracoes.js';
    private const CABECALHO = 'Limite e prazo do banco (etapa E17, P73; desenho L245, L250 e L251)';

    /** @param array<string, string> $esperado */
    #[DataProvider('regras')]
    #[TestDox('A regra $seletor tem os valores do desenho')]
    public function testRegraComOsValoresDoDesenho(string $seletor, array $esperado): void
    {
        $declaracoes = self::declaracoes(self::semComentarios(self::bloco()), $seletor);

        foreach ($esperado as $propriedade => $valor) {
            self::assertSame($valor, $declaracoes[$propriedade] ?? null, sprintf('%s { %s }', $seletor, $propriedade));
        }
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function regras(): iterable
    {
        yield 'grade (L245)' => ['.pe-config-grade', ['display' => 'grid', 'grid-template-columns' => 'repeat(auto-fit, minmax(200px, 1fr))', 'gap' => '12px', 'padding' => '12px 16px', 'background' => 'var(--pe-rodape-bg)']];
        yield 'rótulo (L250, L251)' => ['.pe-config-rotulo', ['display' => 'flex', 'flex-direction' => 'column', 'gap' => '5px', 'font-size' => '12px', 'font-weight' => 'bold', 'color' => 'var(--pe-texto-3)']];
        yield 'frase de erro (sistema)' => ['.pe-config-banco-erro', ['font-size' => '12px', 'font-weight' => 'normal', 'color' => 'var(--pe-perigo)']];
        yield 'frase escondida' => ['.pe-config-banco-erro[hidden]', ['display' => 'none']];
    }

    #[TestDox('O fundo da grade é o #f7f9fb do desenho, pelo token do rodapé; o bloco não tem hover nem cor solta e todo seletor tem o prefixo da tela')]
    public function testBlocoDaEtapa(): void
    {
        $css = (string) file_get_contents(self::CSS);
        self::assertMatchesRegularExpression('/--pe-rodape-bg:\s+#f7f9fb;/', $css);

        $bloco = self::semComentarios(self::bloco());
        self::assertStringNotContainsString(':hover', $bloco);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b/', $bloco, 'cor só por token --pe-*');
        self::assertDoesNotMatchRegularExpression('/(^|\})\s*(?!\.pe-)[^{}\s][^{}]*\{/', $bloco, 'todo seletor com o prefixo da tela');
    }

    #[TestDox('Grava ao sair do campo (change, não a cada tecla), com o nome do campo no corpo, pelo `enviar` com prazo da aba')]
    public function testGravaAoSairDoCampo(): void
    {
        $js = (string) file_get_contents(self::JS);
        $gravar = self::corpo($js, 'async function gravarLimiteDoBanco(campo) {');

        self::assertStringNotContainsString("addEventListener('input'", $js, 'não grava a cada tecla');
        self::assertStringContainsString("const campo = elemento(evento.target, '[data-config-banco-campo]');", self::corpo($js, "document.addEventListener('change', function (evento) {"));
        self::assertStringContainsString("corpo[nome] = campo.value === '' ? null : Number(campo.value);", $gravar, 'vazio vai como null, e o servidor recusa');
        self::assertStringContainsString('const resultado = await enviar(grade.dataset.url, corpo, null);', $gravar, 'o token ajax vem do interceptador, como a caixa do trabalho externo');
        self::assertSame(1, substr_count($js, 'fetch('), 'um fetch só, o de `enviar` (com prazo)');
    }

    #[TestDox('Recusa ou falta de resposta: o campo volta ao valor gravado e a frase do servidor aparece; deu certo: fica o que o servidor devolveu')]
    public function testRecusaVoltaAoGravado(): void
    {
        $gravar = self::corpo((string) file_get_contents(self::JS), 'async function gravarLimiteDoBanco(campo) {');

        self::assertMatchesRegularExpression(
            "/if \\(!resultado\\.ok\\) \\{\\s+campo\\.value = campo\\.dataset\\.gravado \\|\\| '';\\s+mostrarErroDoBanco\\(campo, resultado\\.erro\\);\\s+return;\\s+\\}/",
            $gravar,
        );
        self::assertMatchesRegularExpression(
            "/const gravado = resultado\\.dados\\[nome\\];\\s+if \\(typeof gravado === 'number'\\) \\{\\s+campo\\.dataset\\.gravado = String\\(gravado\\);\\s+campo\\.value = String\\(gravado\\);\\s+\\}/",
            $gravar,
        );
        self::assertStringContainsString('campo.readOnly = true;', $gravar, 'só de leitura enquanto grava (disabled tiraria o foco)');
        self::assertStringNotContainsString('campo.disabled', $gravar);
        self::assertStringNotContainsString('alert(', $gravar);
        self::assertStringContainsString("erro.textContent = texto || '';", self::corpo((string) file_get_contents(self::JS), 'function mostrarErroDoBanco(campo, texto) {'), 'a frase por textContent');
    }

    private static function bloco(): string
    {
        $css = (string) file_get_contents(self::CSS);
        $inicio = strpos($css, self::CABECALHO);
        self::assertIsInt($inicio, 'o bloco da E17 tem o cabeçalho combinado');

        $comeco = (int) strrpos(substr($css, 0, $inicio), '/*');
        $seguinte = preg_match('#\n/\* (──|---|=====)#u', $css, $achado, PREG_OFFSET_CAPTURE, $inicio) === 1 ? $achado[0][1] : strlen($css);

        return substr($css, $comeco, $seguinte - $comeco);
    }

    private static function semComentarios(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return array<string, string> */
    private static function declaracoes(string $css, string $seletor): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $regras, PREG_SET_ORDER);
        foreach ($regras as [, $cabeca, $corpo]) {
            $nome = (string) preg_replace(['/\s*,\s*/', '/\s+/'], [',', ' '], trim($cabeca));
            if ($nome !== $seletor) {
                continue;
            }
            $declaracoes = [];
            foreach (array_filter(array_map('trim', explode(';', $corpo))) as $declaracao) {
                [$propriedade, $valor] = array_map('trim', explode(':', $declaracao, 2)) + [1 => ''];
                $declaracoes[$propriedade] = (string) preg_replace('/\s+/', ' ', $valor);
            }

            return $declaracoes;
        }

        self::fail('Regra não encontrada: ' . $seletor);
    }

    /** Corpo de uma função, delimitado por contagem de chaves a partir da assinatura. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, 'faltou: ' . $assinatura);
        $abre = strpos($fonte, '{', $inicio + strlen($assinatura) - 1);
        self::assertIsInt($abre);

        $profundidade = 0;
        for ($i = $abre, $n = strlen($fonte); $i < $n; $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}' && --$profundidade === 0) {
                return substr($fonte, $inicio, $i - $inicio + 1);
            }
        }

        self::fail('o corpo não fecha: ' . $assinatura);
    }
}
