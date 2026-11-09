<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E13 do desenho novo do Ponto (`docs/specs/ponto-desenho-0811.md`, P5 e base do P20): toda batida leva a hora do
 * aparelho no toque (`horaAparelho`, epoch em ms, guardada pelo servidor só como informação) e um identificador único
 * do envio (`idEnvio`, UUID v4), o mesmo em toda nova tentativa daquela batida, para o servidor não gravar duas vezes.
 *
 * Lido no texto dos scripts (o container não tem node): o módulo faz o identificador sem nunca lançar e devolve o
 * código HTTP e a marca de falha de rede junto da classificação de sempre; a tela do ponto e o atalho do cabeçalho
 * mandam os dois campos, com a hora tirada no toque e não no envio.
 */
final class HoraDoAparelhoEEnvioUnicoJsTest extends TestCase
{
    private static function modulo(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-batida.js');
    }

    private static function tela(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/ponto/index.html.twig');
    }

    private static function atalho(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/presenca-ponto.js');
    }

    /** Corpo de uma função, delimitado por contagem de chaves a partir da assinatura. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, 'faltou: ' . $assinatura);
        $abre = strpos($fonte, '{', $inicio);
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

    #[TestDox('O módulo faz o identificador do envio: randomUUID em contexto seguro, v4 montado fora dele, sempre minúsculo e sem lançar')]
    public function testModuloFazOIdentificadorDoEnvio(): void
    {
        $id = self::corpo(self::modulo(), 'function novoIdDeEnvio() {');

        self::assertMatchesRegularExpression(
            "/try \\{\\s+if \\(typeof crypto !== 'undefined' && typeof crypto\\.randomUUID === 'function'\\) \\{\\s+return String\\(crypto\\.randomUUID\\(\\)\\)\\.toLowerCase\\(\\);/",
            $id,
            'randomUUID só existe em contexto seguro, e a chamada fica dentro do try: o identificador nunca lança'
        );
        self::assertMatchesRegularExpression(
            '/try \{\s+crypto\.getRandomValues\(bytes\);\s+\} catch \(erro\) \{\s+for \(/',
            $id,
            'fora do contexto seguro (o nip.io do dev), o v4 sai do getRandomValues, e sem ele do Math.random'
        );
        self::assertStringContainsString('bytes[6] = (bytes[6] & 0x0f) | 0x40;', $id, 'versão 4');
        self::assertStringContainsString('bytes[8] = (bytes[8] & 0x3f) | 0x80;', $id, 'variante RFC 4122');
        self::assertStringContainsString("b.toString(16).padStart(2, '0')", $id, 'hexadecimal minúsculo, dois dígitos por byte');
        self::assertStringContainsString(
            "return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);",
            $id
        );
        self::assertMatchesRegularExpression('/window\.PontoBatida = Object\.freeze\(\{[^}]*\bnovoIdDeEnvio,/', self::modulo(), 'exportado');
    }

    #[TestDox('O envio devolve o código HTTP e a falha de rede junto da classificação; falhaDeRede só no catch do fetch')]
    public function testEnvioDevolveStatusEFalhaDeRede(): void
    {
        $envio = self::corpo(self::modulo(), 'async function enviarBatida(');

        self::assertStringContainsString("return { tipo: 'atrasado', mensagem: null, data: null, status: null, falhaDeRede: false };", $envio);
        self::assertStringContainsString('const status = response.status;', $envio);
        foreach (["tipo: 'sucesso', mensagem: data.message, data, status, falhaDeRede: false", "tipo: 'recusa', mensagem: data.message, data, status, falhaDeRede: false", "tipo: 'nao_entrou', mensagem: null, data, status, falhaDeRede: false", "tipo: 'sem_confirmacao', mensagem: null, data, status, falhaDeRede: false"] as $retorno) {
            self::assertStringContainsString($retorno, $envio, 'a classificação de sempre, com os dois campos novos');
        }

        self::assertSame(1, substr_count($envio, 'falhaDeRede: true'), 'falha de rede é um lugar só');
        $catch = strpos($envio, '} catch (error) {');
        self::assertIsInt($catch);
        self::assertGreaterThan($catch, strpos($envio, 'falhaDeRede: true'), 'só quando o fetch falhou: nenhuma resposta voltou');
        self::assertStringContainsString(
            "return { tipo: semRede ? 'sem_rede' : 'sem_confirmacao', mensagem: null, data: null, status: null, falhaDeRede: true };",
            $envio,
            'a classificação da falha de rede não muda'
        );
    }

    #[TestDox('A tela do ponto manda idEnvio e horaAparelho, e a hora sai do toque, antes de qualquer espera')]
    public function testTelaMandaOsDoisCamposDoToque(): void
    {
        $clique = self::corpo(self::tela(), "btnPonto.addEventListener('click', async function() {");

        self::assertMatchesRegularExpression(
            '/const momentoDoToque = performance\.now\(\);\s+(?:\/\/[^\n]*\n\s*)*const horaAparelho = Date\.now\(\);\s+const payload = \{ tipo: tipoRegistro\.value, idEnvio: idDoEnvio\(\), horaAparelho \};/',
            $clique,
            'a hora do aparelho e o identificador são do toque, junto do momento do toque'
        );
        $hora = strpos($clique, 'const horaAparelho = Date.now();');
        $espera = strpos($clique, 'await ');
        self::assertIsInt($hora);
        self::assertIsInt($espera);
        self::assertLessThan($espera, $hora, 'a hora vem antes da leitura da posição e do envio: é a do toque, não a do envio');
        self::assertSame(1, substr_count($clique, 'horaAparelho = '), 'a hora do aparelho é tirada uma vez, no toque');

        $id = self::corpo(self::tela(), 'function idDoEnvio() {');
        self::assertStringContainsString("return typeof PontoBatida.novoIdDeEnvio === 'function' ? PontoBatida.novoIdDeEnvio() : undefined;", $id, 'módulo antigo no cache não trava o botão');
    }

    #[TestDox('O atalho do cabeçalho manda os dois campos, tirados no toque; fila sem internet não há no atalho')]
    public function testAtalhoMandaOsDoisCampos(): void
    {
        $atalho = self::atalho();
        $bater = self::corpo($atalho, 'async function bater() {');

        self::assertMatchesRegularExpression(
            '/var momentoDoToque = performance\.now\(\);[^\n]*\n\s*(?:\/\/[^\n]*\n\s*)*var horaAparelho = Date\.now\(\);\s+var payload = \{ tipo: atual\.acao, idEnvio: typeof PB\.novoIdDeEnvio === \'function\' \? PB\.novoIdDeEnvio\(\) : undefined, horaAparelho: horaAparelho \};/',
            $bater
        );
        $hora = strpos($bater, 'var horaAparelho = Date.now();');
        $posicao = strpos($bater, 'await PB.lerPosicaoComPrazo(');
        self::assertIsInt($hora);
        self::assertIsInt($posicao);
        self::assertLessThan($posicao, $hora, 'a hora é a do toque, não a da chegada da posição');

        foreach (['PontoFila', 'semInternet', 'indexedDB'] as $daFila) {
            self::assertStringNotContainsString($daFila, $atalho, 'a fila é só da tela do ponto: ' . $daFila);
        }
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $atalho);
    }
}
