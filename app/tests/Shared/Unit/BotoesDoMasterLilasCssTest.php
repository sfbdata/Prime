<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedido M5.2 do dono: todo botão exclusivo do Master (ROLE_SUPER_ADMIN), fora do painel /master, é lilás, para
 * distinguir à vista o que só o Master tem. Os tokens moram em bj-padrao.css e cada componente os usa por var().
 * Que aparece certo na tela é do smoke do dono.
 */
final class BotoesDoMasterLilasCssTest extends TestCase
{
    private const APP = __DIR__ . '/../../../';

    #[TestDox('bj-padrao.css define os tokens do lilás do Master no tema claro')]
    public function testTokensNoTemaClaro(): void
    {
        $css = (string) file_get_contents(self::APP . 'public/css/bj-padrao.css');

        foreach ([
            '--bj-master: #7a3e8f;',
            '--bj-master-escuro: #5f2f70;',
            '--bj-master-claro: #f3e9f7;',
            '--bj-master-borda: #e6d6ee;',
            '--bj-master-texto: #4b2259;',
            '--bj-master-icone: #7a3e8f;',
        ] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertMatchesRegularExpression('/:root\s*\{[^}]*--bj-master: #7a3e8f;/', $css);
    }

    #[TestDox('bj-padrao.css redefine claro, borda, texto e ícone no tema escuro')]
    public function testTokensNoTemaEscuro(): void
    {
        $css = (string) file_get_contents(self::APP . 'public/css/bj-padrao.css');

        self::assertSame(1, preg_match('/html\[data-bs-theme="dark"\]\s*\{([^}]*)\}/', $css, $m));
        foreach ([
            '--bj-master-claro: rgba(122, 62, 143, .22);',
            '--bj-master-borda: rgba(199, 154, 216, .45);',
            '--bj-master-texto: #e3c8ee;',
            '--bj-master-icone: #c79ad8;',
        ] as $token) {
            self::assertStringContainsString($token, $m[1]);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function botoes(): iterable
    {
        yield 'Menu do usuário, ícone dos itens' => ['public/css/menu-usuario.css', '.mu-grupo-master .mu-item[data-item-master] > .bi { color: var(--bj-master-icone)'];
        yield 'Menu do usuário, rótulo ★' => ['public/css/menu-usuario.css', '.mu-grupo-master .mu-grupo-rotulo { color: var(--bj-master-icone)'];
        yield 'Meu Perfil, menu do Master' => ['public/css/menu-usuario.css', '.mu-master-menu { width: 290px; padding: 6px 0; border: none; border-radius: 0 0 4px 4px; background: var(--bj-master)'];
        yield 'Perfis, Ver como' => ['public/css/perfis.css', '.pf-ver-como-btn.is-ativo, .pf-page .pf-ver-como-btn.is-ativo:hover, .pf-page .pf-ver-como-btn.is-ativo:focus { background: var(--bj-master)'];
        yield 'Contribua, salvar pagamento' => ['public/css/contribua.css', '.ct-botao-azul[data-master-salvar] { background: var(--bj-master)'];
        yield 'Contribua, liberar pedido' => ['public/css/contribua.css', '.ct-pedido-liberar { background: var(--bj-master)'];
        yield 'Contribua, recusar e atender' => ['public/css/contribua.css', '.ct-pedido-recusar, .ct-sug-atender { background: var(--bj-master-claro)'];
        yield 'Contribua, interruptor Indique' => ['public/css/contribua.css', '.ct-indique-chave[aria-checked="true"] .ct-indique-trilho { background: var(--bj-master)'];
        yield 'Contribua, abrir configuração' => ['public/css/contribua-checkout.css', '.co-abrir-master { background: var(--bj-master-claro)'];
        yield 'Integração bancária, salvar' => ['public/css/contribua-cora.css', '.cc-btn.cc-btn-azul { background: var(--bj-master)'];
        yield 'Integração bancária, contorno' => ['public/css/contribua-cora.css', '.cc-btn { background: var(--bj-master-claro)'];
        yield 'Integração bancária, modo' => ['public/css/contribua-cora.css', '.cc-modo.is-on { border-color: var(--bj-master-icone)'];
        yield 'Integração bancária, recurso' => ['public/css/contribua-cora.css', '.cc-recurso[aria-pressed="true"] { border-color: var(--bj-master-borda)'];
        yield 'Integração bancária, simular' => ['public/css/contribua-cora.css', '.cc-simular button { background: var(--bj-master-claro)'];
        yield 'Campanha, preto vira cheio' => ['public/css/contribua-campanha.css', '.cp-pbtn--preto { background: var(--bj-master)'];
        yield 'Campanha, contorno' => ['public/css/contribua-campanha.css', '.cp-pbtn--contorno, .cp-ped-botao { background: var(--bj-master-claro)'];
        yield 'Campanha, multiplicador' => ['public/css/contribua-campanha.css', '.cp-mult button.is-on { background: var(--bj-master)'];
        yield 'Campanha, interruptor' => ['public/css/contribua-campanha.css', '.cp-chave[aria-checked="true"] { background: var(--bj-master)'];
        yield 'Ponto, interruptor global' => ['public/css/ponto-eletronico.css', '.pe-config-switch[data-config-global][aria-checked="true"] { background: var(--bj-master)'];
        yield 'Aviso de pedidos de espaço' => ['public/css/aviso-espaco.css', '.ae-aviso--pedidos a.ae-aumentar { background: var(--bj-master)'];
        yield 'Aviso da cota, dispensar' => ['public/css/aviso-falha-agendada.css', '.af-dispensar.bj-master { background: var(--bj-master-claro)'];
        yield 'Login, salvar link' => ['public/css/auth/entrada.css', '.lg-lk__salvar{background:var(--bj-master)'];
        yield 'Login, demais botões do link' => ['public/css/auth/entrada.css', '.lg-lk__btn{background:var(--bj-master-claro)'];
        yield 'Trabalho Externo, opções do Master' => ['public/css/trabalho-externo.css', '.te-btn-icone.te-btn-master { color: var(--bj-master-icone)'];
    }

    #[DataProvider('botoes')]
    #[TestDox('Botão do Master é lilás: $_dataName')]
    public function testBotaoUsaOLilas(string $arquivo, string $trecho): void
    {
        self::assertStringContainsString($trecho, (string) file_get_contents(self::APP . $arquivo));
    }

    #[TestDox('Campanha: a janela escura usa lilás claro fixo no link do Master')]
    public function testJanelaDaCampanhaTemContrasteNoFundoEscuro(): void
    {
        $css = (string) file_get_contents(self::APP . 'public/css/contribua-campanha.css');

        self::assertStringContainsString('.cp-master a { background: transparent; color: #c79ad8; border-color: #c79ad8; }', $css);
    }

    #[TestDox('Trabalho Externo: o JS põe a classe te-btn-master no botão de opções do Master')]
    public function testJsPoeAClasseDoMaster(): void
    {
        $js = (string) file_get_contents(self::APP . 'public/js/trabalho-externo.js');

        self::assertStringContainsString("class: 'te-btn-icone te-btn-master', title: 'Opções do Master'", $js);
    }

    #[TestDox('Login carrega o bj-padrao.css para ter os tokens do lilás')]
    public function testLoginCarregaOsTokens(): void
    {
        $twig = (string) file_get_contents(self::APP . 'templates/security/login.html.twig');

        self::assertStringContainsString("asset('css/bj-padrao.css')", $twig);
    }
}
