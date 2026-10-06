<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o polling do drawer dos agentes da BlueJus IA (public/js/pasta-ia-agentes.js), irmão da
 * guarda do Push: cada pergunta é uma requisição ao servidor, então ele PAUSA com a página oculta
 * e com o drawer fechado, retoma ao abrir/voltar e espaça as perguntas (2 s → 5 s → 10 s).
 *
 * Regex não executa JS: isto prova que as peças estão lá, não que o navegador as usa. O
 * comportamento na tela é smoke do dono.
 */
#[CoversNothing]
final class PastaIaAgentesPollingArquiteturaTest extends TestCase
{
    private static function modulo(): string
    {
        $conteudo = file_get_contents(\dirname(__DIR__, 2) . '/public/js/pasta-ia-agentes.js');
        self::assertIsString($conteudo);

        return $conteudo;
    }

    #[TestDox('pausa com a página oculta e retoma ao voltar (visibilitychange + document.hidden)')]
    public function testPausaComPaginaOculta(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("addEventListener('visibilitychange'", $js);
        self::assertStringContainsString('document.hidden', $js);
    }

    #[TestDox('só pergunta com o drawer aberto: fechar pausa, abrir retoma')]
    public function testPausaComDrawerFechado(): void
    {
        $js = self::modulo();

        self::assertStringContainsString('function ativo() { return !document.hidden && aberto(); }', $js);
        self::assertStringContainsString("classList.remove('ia-drawer-aberto');\n            pausar();", $js, 'fechar pausa');
        self::assertStringContainsString("carregarPainel();\n            retomar();", $js, 'abrir carrega e retoma');
    }

    #[TestDox('backoff: 2 s nos primeiros 30 s, depois 5 s, depois 10 s; sem intervalo fixo')]
    public function testBackoff(): void
    {
        $js = self::modulo();

        self::assertMatchesRegularExpression('/\[\[30000, 2000\], \[\d+, 5000\], \[Infinity, 10000\]\]/', $js);
        self::assertStringContainsString('setTimeout(perguntar, intervaloPara(', $js);
        self::assertStringNotContainsString('setInterval(', $js, 'intervalo fixo ignoraria a pausa e o backoff');
    }

    #[TestDox('o painel é buscado UMA vez, na primeira abertura (data-ia-carregado), e a lista recarregada é a DO AGENTE')]
    public function testCarregaPainelUmaVezERecarregaPorAgente(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("getAttribute('data-ia-carregado') === '1'", $js);
        self::assertStringContainsString('function recarregarAgente(secao, idNova)', $js);
        self::assertStringContainsString("closest('[data-ia-agente]')", $js);
    }
}
