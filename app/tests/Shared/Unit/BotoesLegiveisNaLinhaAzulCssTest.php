<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pedido M6 do Farlei (23/11): na linha que fica azul (#2b7ab0) sob o mouse, a regra que pinta tudo de branco
 * deixava os botões de fundo branco (link, Ver, ações) com texto branco sobre branco. Agora os botões guardam a
 * cor própria, como já fazia `table.bjp-linhas` do bj-padrao.css, e o botão sem fundo acende em branco translúcido
 * no hover em vez do fundo claro que apagava o ícone branco. Que aparece certo na tela é do smoke do dono.
 */
final class BotoesLegiveisNaLinhaAzulCssTest extends TestCase
{
    private const APP = __DIR__ . '/../../../';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function regras(): iterable
    {
        yield 'Push, publicações' => ['public/css/push-processual.css', '.pp-linha--dado:hover, .pp-linha--dado:hover *:not(:is(.pp-icone, .pp-icone *, .pp-ver, .pp-ver *, .pp-acao, .pp-acao *)) { color: #fff !important; }', '.pp-linha--dado:hover, .pp-linha--dado:hover * {'];
        yield 'Push, OABs' => ['public/css/push-processual.css', '.pp-linha--oab:hover, .pp-linha--oab:hover *:not(:is(.pp-icone, .pp-icone *, .pp-ver, .pp-ver *, .pp-acao, .pp-acao *)) { color: #fff !important; }', '.pp-linha--oab:hover, .pp-linha--oab:hover * {'];
        yield 'Arquivos da pasta' => ['public/css/pasta-arquivos.css', '.fm-arquivo:hover, .fm-arquivo:hover *:not(:is(.dropdown-menu, .dropdown-menu *)) { color: #fff !important; }', '.fm-arquivo:hover, .fm-arquivo:hover * {'];
        yield 'Master, blog' => ['public/css/master-blog.css', '.mp .bja-linha:hover *:not(:is(.bja-acao, .bja-acao *)) { color: #fff !important;', '.mp .bja-linha:hover * {'];
        yield 'Master, revisão da OAB' => ['public/css/master.css', '.mp .roab-linha:hover *:not(:is(.roab-reverif, .roab-reverif *)) { color: #fff !important;', '.mp .roab-linha:hover * {'];
        yield 'Pasta, clientes' => ['templates/pasta/show.html.twig', '.cliente-linha:hover *:not(.cliente-avatar):not(:is(.bjp-btn, .bjp-btn *, .bjp-ico, .bjp-ico *)) { color: #fff !important; }', '.cliente-linha:hover *:not(.cliente-avatar) {'];
    }

    #[DataProvider('regras')]
    #[TestDox('Linha azul não pinta de branco o botão de fundo branco: $_dataName')]
    public function testBotaoGuardaACorPropria(string $arquivo, string $regraNova, string $regraVelha): void
    {
        $conteudo = (string) file_get_contents(self::APP . $arquivo);

        self::assertStringContainsString($regraNova, $conteudo);
        self::assertStringNotContainsString($regraVelha, $conteudo, 'a regra antiga pintava todos os descendentes');
    }

    #[TestDox('Botão sem fundo acende em branco translúcido no hover dentro da linha azul')]
    public function testBotaoSemFundoAcendeTranslucido(): void
    {
        $esperado = [
            'public/css/pasta-arquivos.css' => '.fm-arquivo:hover .fm-arq-menu:hover { background: rgba(255,255,255,.2); }',
            'public/css/pasta-push-cliente.css' => '.ps-push-cliente-item:hover :is(.ps-push-cliente-estrela, .ps-push-cliente-remover):hover:not(:disabled) { background: rgba(255,255,255,.2); }',
            'public/css/perfis.css' => '.pf-page .pf-linha:hover .pf-col-acoes :is(.pf-acao-editar, .pf-acao-excluir):hover { background: rgba(255,255,255,.2); }',
            'public/css/dashboard-perfil-pessoa.css' => '.db-page .db-table tbody tr:hover .db-pf-abrir:focus-visible:not(:disabled) { background: rgba(255,255,255,.2); }',
            'templates/pasta/show.html.twig' => '.cliente-linha:hover a.cliente-abrir:hover { background: rgba(255,255,255,.2) !important; }',
            'public/css/pasta-explorador.css' => '.pex-item:hover :is(.pex-menu:hover, .pex-menu[aria-expanded="true"]) { background: rgba(255,255,255,.2); }',
        ];
        foreach ($esperado as $arquivo => $regra) {
            self::assertStringContainsString($regra, (string) file_get_contents(self::APP . $arquivo), $arquivo);
        }
    }

    #[TestDox('Etiqueta de tipo da OAB no Meu Perfil fica translúcida na linha azul, como a de situação')]
    public function testEtiquetaDoTipoDaOab(): void
    {
        $css = (string) file_get_contents(self::APP . 'public/css/meu-perfil.css');

        self::assertStringContainsString('.mp-oab-linha:hover .mp-oab-situacao, .mp-oab-linha:hover .mp-oab-tipo { background: rgba(255,255,255,.2) !important;', $css);
    }
}
