<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As peças da aba "Perguntar" que o HTML não mostra (spec inteligencia-drawer-da-pasta, F3):
 * o polling do `pasta-ia-conversa.js` segue a mesma regra do drawer (pausa com a página oculta e
 * com o drawer fechado, espaça 2 s → 5 s → 10 s, nada de intervalo fixo), o Enter envia, o envio
 * leva `texto` e o `_token` do drawer; e o CSS tem os valores do desenho (mensagens, degradê,
 * cartão de carregando com o `iaShim`) e desliga a animação no "reduzir movimento".
 *
 * Regex não executa JS nem pinta CSS: isto prova que as peças estão lá. A tela é smoke do dono.
 */
#[CoversNothing]
#[Group('inteligencia')]
final class PastaIaConversaJsCssTest extends TestCase
{
    private static function arquivo(string $relativo): string
    {
        $conteudo = file_get_contents(\dirname(__DIR__, 3) . '/public/' . $relativo);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    private static function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', self::arquivo('css/pasta-ia-agentes.css'));
    }

    #[TestDox('polling: pausa com a página oculta e com o drawer fechado; backoff 2 s, 5 s, 10 s; sem setInterval')]
    public function testPolling(): void
    {
        $js = self::arquivo('js/pasta-ia-conversa.js');

        self::assertStringContainsString("addEventListener('visibilitychange'", $js);
        self::assertStringContainsString('function ativo() { return !document.hidden && !drawer.hidden; }', $js);
        self::assertMatchesRegularExpression('/\[\[30000, 2000\], \[\d+, 5000\], \[Infinity, 10000\]\]/', $js);
        self::assertStringContainsString('setTimeout(perguntarDeNovo, intervaloPara(', $js);
        self::assertStringNotContainsString('setInterval(', $js);
        self::assertStringContainsString("getAttribute('data-ia-em-andamento') === '1'", $js);
    }

    #[TestDox('envio: Enter envia, POST com texto e o _token do drawer, motivos de disponibilidade travam')]
    public function testEnvio(): void
    {
        $js = self::arquivo('js/pasta-ia-conversa.js');

        self::assertStringContainsString("if (e.key !== 'Enter' || e.isComposing) { return; }", $js);
        self::assertStringContainsString("dados.append('_token', drawer.getAttribute('data-ia-token') || '');", $js);
        self::assertStringContainsString("dados.append('texto', texto);", $js);
        self::assertStringContainsString("rodape.getAttribute('data-ia-perguntar-url')", $js);
        self::assertStringContainsString("'nao_configurada_na_plataforma', 'desligada_no_escritorio', 'sem_permissao', 'limite_atingido'", $js);
        self::assertStringNotContainsString('innerHTML = d.', $js, 'nenhum texto do servidor vira HTML fora do fragmento do Twig');
        self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string) preg_replace('#/\*.*?\*/#s', '', $js));
    }

    #[TestDox('CSS das mensagens com os valores do desenho (dc L5972): 88%, 9px 12px, raio 4px, 13px/1.5, pre-wrap, degradê e anel')]
    public function testMensagens(): void
    {
        $css = self::css();

        self::assertMatchesRegularExpression('/\.ia-conversa-msg \{[^}]*max-width: 88%;[^}]*padding: 9px 12px;[^}]*border-radius: 4px;[^}]*font-size: 13px;[^}]*line-height: 1\.5;[^}]*white-space: pre-wrap;/', $css);
        self::assertMatchesRegularExpression('/\.ia-conversa-msg--eu \{[^}]*align-self: flex-end;[^}]*background: linear-gradient\(135deg, var\(--ia-azul-claro\), var\(--ia-azul\)\);[^}]*color: #fff;/', $css);
        self::assertMatchesRegularExpression('/\.ia-conversa-msg--ia \{[^}]*align-self: flex-start;[^}]*background: var\(--ia-cartao\);[^}]*box-shadow: inset 0 0 0 1px var\(--ia-anel\);/', $css);
        self::assertStringContainsString('--ia-azul-claro:    #1597bd;', $css);
        self::assertStringContainsString('--ia-azul:          #0b6688;', $css);
        self::assertStringContainsString('--ia-anel:          #e6edf2;', $css);
    }

    #[TestDox('cartão de carregamento (dc 28.10.26): faixa que varre, símbolo girando, etapa em gradiente, passos e barra; o reduzir movimento desliga')]
    public function testCarregando(): void
    {
        $css = self::css();

        self::assertMatchesRegularExpression('/\.ia-carga-varre \{[^}]*animation: iaVarre 1\.6s ease-in-out infinite;/', $css);
        self::assertMatchesRegularExpression('/\.ia-carga-simbolo > img \{[^}]*animation: iaSimbolo 2\.4s/', $css);
        self::assertMatchesRegularExpression('/\.ia-conversa-etapa \{[^}]*animation: iaTexto 2s linear infinite;/', $css);
        self::assertStringContainsString('linear-gradient(90deg, #1fa6cc, #7b6cf6)', $css);
        $reduzir = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'));
        foreach (['.ia-carga-varre,', '.ia-carga-halo,', '.ia-carga-simbolo > img,', '.ia-conversa-etapa,', '.ia-carga-pontos > i,'] as $seletor) {
            self::assertStringContainsString($seletor, $reduzir);
        }
    }

    #[TestDox('rodapé: escondido com [hidden], campo de 40px e botão enviar de 40px em degradê')]
    public function testRodape(): void
    {
        $css = self::css();

        self::assertStringContainsString('.ia-conversa-rodape[hidden] { display: none; }', $css);
        self::assertMatchesRegularExpression('/\.ia-conversa-input \{[^}]*height: 40px;[^}]*padding: 0 13px;[^}]*background: var\(--ia-campo-bg\);[^}]*font-size: 13\.5px;/', $css);
        self::assertMatchesRegularExpression('/\.ia-conversa-enviar \{[^}]*width: 40px;[^}]*height: 40px;[^}]*background: linear-gradient\(135deg, var\(--ia-azul-claro\), var\(--ia-azul\)\);/', $css);
        self::assertStringContainsString('--ia-campo-bg:      #f1f6f9;', $css);
    }
}
