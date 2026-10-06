<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o polling da BlueJus IA na aba Push (public/js/pasta-ia-push.js): cada pergunta é uma
 * requisição ao servidor, então ele PAUSA com a página oculta e com outra aba da pasta aberta,
 * retoma ao voltar e espaça as perguntas (2 s → 5 s → 10 s).
 *
 * Regex não executa JS: isto prova que as peças estão lá, não que o navegador as usa. O
 * comportamento na tela é smoke do dono.
 */
#[CoversNothing]
final class PastaIaPushPollingArquiteturaTest extends TestCase
{
    private static function modulo(): string
    {
        $conteudo = file_get_contents(\dirname(__DIR__, 2) . '/public/js/pasta-ia-push.js');
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

    #[TestDox('para quando a aba Push deixa de estar ativa e retoma ao voltar (eventos de aba do Bootstrap)')]
    public function testPausaForaDaAbaPush(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("addEventListener('hidden.bs.tab', pausar)", $js);
        self::assertStringContainsString("addEventListener('shown.bs.tab', retomar)", $js);
        self::assertStringContainsString("classList.contains('active')", $js);
    }

    #[TestDox('backoff: 2 s nos primeiros 30 s, depois 5 s, depois 10 s; sem intervalo fixo')]
    public function testBackoff(): void
    {
        $js = self::modulo();

        self::assertMatchesRegularExpression('/\[\[30000, 2000\], \[\d+, 5000\], \[Infinity, 10000\]\]/', $js);
        self::assertStringContainsString('setTimeout(perguntar, intervaloPara(', $js);
        self::assertStringNotContainsString('setInterval(', $js, 'intervalo fixo ignoraria a pausa e o backoff');
    }
}
