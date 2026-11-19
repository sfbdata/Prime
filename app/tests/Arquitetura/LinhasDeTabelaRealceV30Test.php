<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guia visual v30: nas tabelas das telas sem desenho próprio, a linha sob o cursor fica azul #2b7ab0 com faixa
 * interna de 4px #1f6f9c e texto branco (`.bjp-linhas` do bj-padrao.css, no lugar do `table-hover` cinza).
 * A lista de pastas (`expediente/_lista`) tem o realce próprio no expediente-lista.css e fica de fora.
 */
#[CoversNothing]
final class LinhasDeTabelaRealceV30Test extends TestCase
{
    private const RAIZ = __DIR__ . '/../../';
    private const TR = 'table.bjp-linhas > tbody > tr:not(.collapse):not(:has(> td[colspan])):hover';
    private const LINHA = self::TR . ' > *';

    /** @return iterable<string, array{string, int}> */
    public static function telas(): iterable
    {
        foreach ([
            'servicedesk/meus_chamados.html.twig' => 1,
            'auth/convites/gerenciar.html.twig' => 1,
            'feriado/index.html.twig' => 1,
            'audit/index.html.twig' => 1,
            'access_request/index.html.twig' => 1,
            'auth/suporte/mensagens.html.twig' => 1,
            'servicedesk/index.html.twig' => 1,
            'auth/meus_convites.html.twig' => 1,
            'agenda/lista.html.twig' => 1,
            'admin/platform/convites.html.twig' => 1,
            'processo/show.html.twig' => 2,
            'processo/_resultado.html.twig' => 1,
            'tenant/_horas_pagas_tab.html.twig' => 1,
            'tenant/edit_user_role.html.twig' => 1,
            'tenant/index.html.twig' => 1,
            'tenant/users.html.twig' => 1,
        ] as $tela => $tabelas) {
            yield $tela => [$tela, $tabelas];
        }
    }

    #[DataProvider('telas')]
    #[TestDox('$tela: tabela com .bjp-linhas e sem o table-hover cinza')]
    public function testTelaUsaOrealce(string $tela, int $tabelas): void
    {
        $conteudo = (string) file_get_contents(self::RAIZ . 'templates/' . $tela);

        self::assertSame($tabelas, preg_match_all('/<table class="table[^"]*\bbjp-linhas\b/', $conteudo));
        self::assertStringNotContainsString('table-hover', $conteudo);
    }

    #[TestDox('Usuários do escritório: sem o hover cinza local, que disputaria com o azul')]
    public function testUsuariosSemHoverLocal(): void
    {
        $conteudo = (string) file_get_contents(self::RAIZ . 'templates/tenant/users.html.twig');

        self::assertStringNotContainsString('tbody tr:hover', $conteudo);
    }

    public function testLinhaAzulComTextoBranco(): void
    {
        $linha = $this->regra(self::LINHA);

        self::assertStringContainsString('background-color: #2b7ab0;', $linha);
        self::assertStringContainsString('box-shadow: inset 0 0 0 9999px #2b7ab0;', $linha);
        self::assertStringContainsString('--bs-table-bg-state: #2b7ab0;', $linha);
        self::assertStringContainsString('color: #fff !important;', $linha);   // vence o .text-muted da própria célula
        self::assertStringContainsString(
            'box-shadow: inset 4px 0 0 #1f6f9c, inset 0 0 0 9999px #2b7ab0;',
            $this->regra(self::TR . ' > :first-child'),
        );
        self::assertStringContainsString('transition: background-color .18s, color .18s', $this->regra('table.bjp-linhas > tbody > tr > *'));
    }

    #[TestDox('Texto branco nos filhos, menos botões, etiquetas, selos, campos e menus')]
    public function testFilhosBrancosComExcecoes(): void
    {
        $css = $this->css();
        self::assertSame(1, preg_match('/' . preg_quote(self::LINHA, '/') . ' :not\(:is\(([^{]+)\)\) \{\s*color: #fff !important;/', $css, $m));
        $excecoes = array_map('trim', explode(',', $m[1]));
        foreach (['.btn', '.bjp-btn', '.bjp-ico', '.btn-action', '.dropdown-menu', '.badge', '.bjp-etiqueta', '.bjp-prazo', 'input', 'select', '.form-control', '.form-select'] as $excecao) {
            self::assertContains($excecao, $excecoes);
        }
    }

    #[TestDox('Ícones de ação (.btn-action, sem fundo) e botão discreto ficam brancos no azul')]
    public function testAcoesSemFundoFicamBrancas(): void
    {
        self::assertStringContainsString('color: #fff !important;', $this->regra(self::TR . ' .btn-action'));
        self::assertStringContainsString('color: #fff !important;', $this->regra(self::TR . ' .bjp-btn--discreto:not(:hover):not(:focus)'));
        self::assertStringContainsString('rgba(255, 255, 255, .2) !important', $this->regra(self::TR . ' .btn-action:hover'));
    }

    #[TestDox('O bloco fica no fim do bj-padrao.css (combinado entre as sessões para não conflitar)')]
    public function testBlocoNoFimDoArquivo(): void
    {
        $css = rtrim($this->css());
        $inicio = strpos($css, '/* Linha de tabela sob o cursor (Guia visual v30)');

        self::assertNotFalse($inicio);
        self::assertStringNotContainsString('bjp-linhas', substr($css, 0, $inicio));
        self::assertStringEndsWith('}', $css);
        self::assertStringNotContainsString('/*', substr($css, $inicio + 10));
    }

    private function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/bj-padrao.css');
    }

    /** Corpo da regra cujo seletor (único, na lista) é exatamente o pedido. */
    private function regra(string $seletor): string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $this->css(), $blocos, \PREG_SET_ORDER);
        foreach ($blocos as $bloco) {
            $seletores = array_map('trim', explode(',', preg_replace('#/\*.*?\*/#s', '', $bloco[1]) ?? ''));
            if (\in_array($seletor, $seletores, true)) {
                return $bloco[2];
            }
        }
        self::fail('Regra não encontrada: ' . $seletor);
    }
}
