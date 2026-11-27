<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Enum\BaseLegalDoEnvio;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pedido do Farlei M1 (C1 e A6): a base legal escolhida na confirmação do Enviar vai no POST da campanha; o texto sem
 * nome vai no modelo; e a prévia leva o público escolhido no exemplo (o rodapé por origem).
 */
#[CoversNothing]
final class ComercialBaseLegalJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function arquivo(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
    }

    #[TestDox('O Enviar pede a base legal na janela do painel e só posta com ela; o modelo leva o semNome; a prévia leva o público')]
    public function testComercialCampanha(): void
    {
        $js = self::arquivo('public/js/master/comercial-campanha.js');

        self::assertStringContainsString('escolha: escolhaDaBaseLegal(),', $js);
        self::assertStringContainsString("}).then(baseLegal => {\n          if (!baseLegal) return;", $js);
        self::assertStringContainsString('frequencia, agendar: agenda, baseLegal }', $js);
        self::assertStringContainsString("semNome: m.semNome || '',", $js);
        self::assertStringContainsString('{ exemplo: Object.assign({}, contagem.exemplo, { publico }) }', $js);
        self::assertStringContainsString("publico: publicoDaSecao() }, secaoDoTeste(aberto))", self::arquivo('public/js/master/comercial-teste.js'), 'o teste leva o público (rodapé) e a seção (Central)');
        preg_match_all("/\\['([a-z_]+)', BJ_T\\.t\\('comercial\\.js\\.base_legal_([a-z_]+)'\\)\\]/", $js, $m);
        self::assertSame(BaseLegalDoEnvio::valores(), $m[1], 'os mesmos valores do servidor');
        self::assertSame($m[1], $m[2], 'cada valor com a chave escrita inteira (o catálogo lê o literal)');
    }

    #[TestDox('A janela do painel tem o select escondido; com escolha, o botão só libera com uma opção e devolve o valor')]
    public function testJanelaDoPainel(): void
    {
        $js = self::arquivo('public/js/master/masters.js');
        $twig = self::arquivo('templates/master/painel.html.twig');

        self::assertStringContainsString('<div class="mp-conf-campo" data-mp-conf-escolha hidden>', $twig);
        self::assertStringContainsString('<select id="mpConfEscolha" name="mpConfEscolha" data-mp-conf-escolha-select></select>', $twig);
        self::assertStringContainsString("ok.disabled = !!palavraExigida || escolhaExigida || (entradaLivre && entrada.value.trim() === '');", $js);
        self::assertStringContainsString('if (escolhaExigida && !selecao.value) return;', $js);
        self::assertStringContainsString('resolver(op.escolha || op.entrada ? valor : true);', $js);
        self::assertStringNotContainsString('innerHTML', $js);
        // B3: com `entrada`, o mesmo campo da palavra vem preenchido e o botão libera com texto.
        self::assertStringContainsString('if (entradaLivre && digitado === \'\') return;', $js);
    }

    #[TestDox('As chaves da base legal existem nos dois idiomas, uma por valor do servidor, sem travessão')]
    public function testCatalogo(): void
    {
        $chaves = ['base_legal_rotulo', 'base_legal_vazio', ...array_map(static fn (string $v): string => 'base_legal_' . $v, BaseLegalDoEnvio::valores())];
        foreach (['pt_BR', 'en'] as $idioma) {
            $js = Yaml::parseFile(self::RAIZ . 'translations/comercial/messages+intl-icu.' . $idioma . '.yaml')['comercial']['js'];
            foreach ($chaves as $chave) {
                self::assertArrayHasKey($chave, $js, $idioma . ': ' . $chave);
                self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $js[$chave]);
            }
        }
    }
}
