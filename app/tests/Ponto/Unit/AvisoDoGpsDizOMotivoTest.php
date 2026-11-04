<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O aviso de GPS da tela do ponto diz o motivo do erro (spec `docs/specs/ponto-gps-erro-que-explica.md`).
 * Antes todo erro virava "Permita o acesso à localização", e quem já tinha permitido procurava uma permissão
 * concedida. PHPUnit não executa JavaScript: isto confere o arranjo do fonte; o comportamento é smoke do dono.
 */
#[CoversNothing]
final class AvisoDoGpsDizOMotivoTest extends TestCase
{
    private const CAMINHO_TELA = __DIR__ . '/../../../templates/ponto/index.html.twig';

    private static function tela(): string
    {
        $conteudo = file_get_contents(self::CAMINHO_TELA);
        self::assertNotFalse($conteudo);

        return $conteudo;
    }

    /** Corpo de `function <nome>(...) { ... }`, contando chaves. */
    private static function funcao(string $nome): string
    {
        $tela = self::tela();
        $inicio = strpos($tela, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, $nome . ' não existe');
        $abre = strpos($tela, '{', $inicio);
        self::assertNotFalse($abre);
        $nivel = 0;
        $tamanho = strlen($tela);
        for ($i = $abre; $i < $tamanho; ++$i) {
            if ('{' === $tela[$i]) {
                ++$nivel;
            } elseif ('}' === $tela[$i]) {
                --$nivel;
                if (0 === $nivel) {
                    return substr($tela, $inicio, $i - $inicio + 1);
                }
            }
        }
        self::fail('corpo de ' . $nome . ' não fecha');
    }

    #[TestDox('o texto antigo, igual para todo erro, saiu')]
    public function testTextoUnicoSaiu(): void
    {
        self::assertStringNotContainsString('Permita o acesso à localização', self::tela());
    }

    #[TestDox('erros 2 e 3 têm texto próprio e botão "Tentar de novo"')]
    public function testPosicaoIndisponivelEDemora(): void
    {
        $tela = self::tela();
        self::assertStringContainsString('const GPS_PERMISSAO_NEGADA = 1;', $tela);
        self::assertStringContainsString('const GPS_POSICAO_INDISPONIVEL = 2;', $tela);
        self::assertStringContainsString('const GPS_DEMOROU = 3;', $tela);
        self::assertStringContainsString("'Não foi possível descobrir sua localização agora. Tente de novo; no computador, deixe o Wi-Fi ligado.'", $tela);
        self::assertStringContainsString("'A busca pela sua localização demorou demais. Tente de novo; no computador, deixe o Wi-Fi ligado.'", $tela);

        $erro = self::funcao('erroGps');
        self::assertStringContainsString('codigo === GPS_PERMISSAO_NEGADA', $erro);
        self::assertStringContainsString('codigo === GPS_DEMOROU ? TEXTO_GPS_DEMOROU : TEXTO_GPS_INDISPONIVEL', $erro);
        self::assertStringContainsString("botaoDoGps('btn-gps-tentar', 'Tentar de novo', tentarGpsDeNovo)", self::funcao('mostrarErroDeBusca'));
    }

    #[TestDox('erro 1 consulta a permissão e reage à troca')]
    public function testPermissaoNegadaConsultaOEstado(): void
    {
        $explicar = self::funcao('explicarPermissaoNegada');
        self::assertStringContainsString("navigator.permissions.query({ name: 'geolocation' })", $explicar);
        self::assertStringContainsString('status.onchange = () => reagirAPermissao(status.state, false);', $explicar);

        $reagir = self::funcao('reagirAPermissao');
        self::assertStringContainsString("estado === 'prompt'", $reagir);
        self::assertStringContainsString('mostrarPedidoDePermissao();', $reagir);
        self::assertStringContainsString('mostrarPermissaoBloqueada();', $reagir);
        // granted: pela troca busca sozinho; vindo do erro, explica (o aparelho bloqueia) sem laço.
        self::assertMatchesRegularExpression("/estado === 'granted'.*?if \\(vindoDoErro\\) \\{\\s*mostrarErroDeBusca\\(TEXTO_GPS_SISTEMA\\);.*?tentarGpsDeNovo\\(\\);/s", $reagir);
    }

    #[TestDox('"prompt": botão "Permitir localização" que pede a posição (abre a janela do navegador)')]
    public function testPrompt(): void
    {
        $pedido = self::funcao('mostrarPedidoDePermissao');
        self::assertStringContainsString("botaoDoGps('btn-gps-permitir', 'Permitir localização', () => {", $pedido);
        self::assertStringContainsString('jaPediuPeloBotao = true;', $pedido);
        self::assertStringContainsString('tentarGpsDeNovo();', $pedido);
        // O erro 1 que volta em "prompt" depois do clique vai ao passo a passo, não ao mesmo botão.
        self::assertStringContainsString("if (estado === 'prompt' && !(vindoDoErro && jaPediuPeloBotao)) {", self::funcao('reagirAPermissao'));
        self::assertStringContainsString('iniciarGps();', self::funcao('tentarGpsDeNovo'));
        self::assertStringContainsString('navigator.geolocation.getCurrentPosition(', self::funcao('iniciarGps'));
    }

    #[TestDox('"denied": passo a passo, com variação para celular')]
    public function testDenied(): void
    {
        $tela = self::tela();
        self::assertStringContainsString("'Clique no ícone à esquerda do endereço do site (o cadeado ou os controles).'", $tela);
        self::assertStringContainsString("'Em Localização, escolha Permitir.'", $tela);
        self::assertStringContainsString("'Recarregue esta página.'", $tela);
        self::assertStringContainsString("const OUTRO_CAMINHO_GPS_COMPUTADOR = 'No Safari do Mac: menu Safari, Ajustes, Sites, Localização, e escolha Permitir para este site.';", $tela);
        self::assertStringContainsString('ehCelular ? OUTRO_CAMINHO_GPS_CELULAR : OUTRO_CAMINHO_GPS_COMPUTADOR', self::funcao('mostrarPermissaoBloqueada'));
        // iPad se identifica como Macintosh: a tela de toque decide.
        self::assertStringContainsString("navigator.maxTouchPoints > 1", $tela);
        self::assertStringContainsString("const VERBO_TOQUE = ehCelular ? 'Toque' : 'Clique';", $tela);
        self::assertStringContainsString("'Toque em Permissões (ou Configurações do site) e depois em Localização.'", $tela);
        self::assertStringContainsString('(ehCelular ? PASSOS_GPS_CELULAR : PASSOS_GPS_COMPUTADOR)', self::funcao('mostrarPermissaoBloqueada'));
    }

    #[TestDox('a busca refeita troca o watch, e o erro de GPS não mexe no botão nem usa innerHTML')]
    public function testRetomadaENaoMexeNoBotao(): void
    {
        $iniciar = self::funcao('iniciarGps');
        self::assertStringContainsString('navigator.geolocation.clearWatch(idDoWatch);', $iniciar);
        self::assertStringContainsString('idDoWatch = navigator.geolocation.watchPosition(', $iniciar);
        // Só a busca mais nova fala no aviso.
        self::assertStringContainsString('const estaTentativa = ++tentativaDoGps;', $iniciar);
        self::assertStringContainsString('if (estaTentativa === tentativaDoGps) {', $iniciar);
        self::assertStringContainsString('getCurrentPosition(aplicarPosicao, erroDestaTentativa, { timeout: 10000 });', $iniciar);
        self::assertStringNotContainsString(', erroGps,', $iniciar);

        foreach (['erroGps', 'reagirAPermissao', 'explicarPermissaoNegada', 'mostrarGps', 'mostrarPermissaoBloqueada', 'tentarGpsDeNovo'] as $nome) {
            $corpo = self::funcao($nome);
            self::assertStringNotContainsString('btnPonto', $corpo, $nome);
            self::assertStringNotContainsString('innerHTML', $corpo, $nome);
        }
    }

    #[TestDox('textos novos sem travessão')]
    public function testSemTravessao(): void
    {
        $tela = self::tela();
        $inicio = strpos($tela, 'const GPS_PERMISSAO_NEGADA');
        $fim = strpos($tela, 'function erroGps(');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);
        $trecho = substr($tela, $inicio, $fim - $inicio);
        self::assertStringNotContainsString('—', $trecho);
        self::assertStringNotContainsString('–', $trecho);
    }
}
