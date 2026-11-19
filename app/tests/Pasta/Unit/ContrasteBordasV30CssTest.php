<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Guia visual v30, itens A3 (contraste, cor não é o único sinal) e A4 (bordas suaves), só tema claro. */
#[CoversNothing]
#[Group('pasta')]
final class ContrasteBordasV30CssTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private function ler(string $caminho): string
    {
        return (string) file_get_contents(self::RAIZ . $caminho);
    }

    #[TestDox('Os tokens do tema claro da Pasta usam os valores do guia v30')]
    public function testTokensDaPasta(): void
    {
        $css = $this->ler('public/css/pasta-show.css');
        foreach (['--ps-muted:         #5f7684;', '--ps-faint:         #5f7684;', '--ps-np-sub:         #5f7684;', '--ps-tl-fraco:     #5f7684;', '--ps-push-linha:    #d6e0e7;', '--ps-border-ctl:    #d6e0e7;'] as $token) {
            self::assertStringContainsString($token, $css);
        }
    }

    #[TestDox('Os tokens do tema claro de Minhas Metas usam os valores do guia v30')]
    public function testTokensDeMinhasMetas(): void
    {
        $css = $this->ler('public/css/minhas-metas.css');
        foreach (['--mm-muted:         #5f7684;', '--mm-faint:         #5f7684;', '--mm-border-ctl:    #d6e0e7;'] as $token) {
            self::assertStringContainsString($token, $css);
        }
        self::assertStringContainsString('.mm-caixa-icone { font-size: 15px; color: #7b93a2; }', $css);
    }

    #[TestDox('O expediente usa o texto de apoio e a borda de controle do guia v30')]
    public function testExpedienteLista(): void
    {
        $css = $this->ler('public/css/expediente-lista.css');
        self::assertStringContainsString('--ex-menu-subtitulo: #5f7684;', $css);
        self::assertStringContainsString('--ex-mais-borda:   #7b93a2;', $css);
    }

    #[TestDox('A faixa de andamento não usa mais o cinza #9aabb7 nem a opacidade do botão de etapa')]
    public function testAndamentoSemCinzaClaro(): void
    {
        $css = $this->ler('public/css/pasta-andamento.css');
        self::assertStringNotContainsString('#9aabb7', $css);
        self::assertStringNotContainsString('opacity: .55', $css);
        self::assertMatchesRegularExpression('/\.ps-and-rotulo \{[^}]*color: #5f7684/', $css);
    }

    #[TestDox('O botão Cadastro mostra um ícone diferente por estado, em vez de só um ponto colorido')]
    public function testCabecalhoComIconePorEstado(): void
    {
        $twig = $this->ler('templates/pasta/_cabecalho.html.twig');
        self::assertStringContainsString('bi-check-circle-fill', $twig);
        self::assertStringContainsString('bi-exclamation-circle-fill', $twig);
        self::assertStringNotContainsString('<span class="ps-cad-ponto', $twig);
    }

    #[TestDox('O ponto do Push processual é um ícone por tipo: juízo e cartório')]
    public function testPushComIconePorTipo(): void
    {
        $twig = $this->ler('templates/pasta/_push_processual.html.twig');
        self::assertStringContainsString('bi-bank2', $twig);
        self::assertStringContainsString('bi-building', $twig);
        self::assertStringNotContainsString('<span class="ps-push-ponto', $twig);
    }
}
