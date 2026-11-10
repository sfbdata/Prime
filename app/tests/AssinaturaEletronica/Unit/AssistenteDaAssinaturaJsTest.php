<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Assistente de novo documento (etapas 1 e 2), pela conferência ✓/✗ com o desenho 17 de 09/11/2026, lido no
 * `public/js/assinatura.js` como texto: não há navegador no PHPUnit. Ver o assistente funcionando é smoke do dono.
 */
#[CoversNothing]
final class AssistenteDaAssinaturaJsTest extends TestCase
{
    private const APP = __DIR__ . '/../../..';

    private function js(): string
    {
        return (string) file_get_contents(self::APP . '/public/js/assinatura.js');
    }

    /** Corpo de uma função do primeiro nível do arquivo (4 espaços de recuo), até a chave que a fecha. */
    private function funcao(string $nome): string
    {
        $js = $this->js();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'função ' . $nome . ' não encontrada');
        $fim = strpos($js, "\n    }\n", $inicio);
        self::assertNotFalse($fim, 'fim da função ' . $nome);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('Escolher um modelo começa um documento novo: o rascunho retomado não passa adiante (desenho L370)')]
    public function testModeloNaoGravaPorCimaDoRascunho(): void
    {
        $usar = $this->funcao('usar');

        self::assertStringNotContainsString('rasc', $usar, 'o salvar depois de escolher o modelo cria outro documento');
        self::assertStringContainsString('s.w = novoW({ passo: 2,', $usar);
    }

    #[TestDox('Prazo: vazio vale 7; o resto vai como foi digitado, sem cortar para 1 a 90 (o servidor recusa com a regra)')]
    public function testPrazoNaoCortaEmSilencio(): void
    {
        $dados = $this->funcao('dadosDoRascunho');

        self::assertStringContainsString("dias = bruto === '' ? 7 : Number(bruto)", $dados);
        self::assertStringContainsString('prazoDias: dias,', $dados);
        self::assertStringNotContainsString('Math.min(90', $this->js());
        self::assertStringNotContainsString('Math.max(1, parseInt(w.dias', $this->js());
    }

    #[TestDox('"Salvar rascunho" volta à lista na aba em que estava (wSalvar, L461); as outras voltas vão para Documentos')]
    public function testSalvarRascunhoMantemAAba(): void
    {
        self::assertStringContainsString('irParaLista(true);', $this->funcao('salvarRascunho'));
        self::assertSame(1, substr_count($this->js(), 'irParaLista(true)'), 'só o salvar mantém a aba');
        self::assertStringContainsString("if (!manterAba) { s.aba = 'docs'; }", $this->funcao('irParaLista'));
    }

    #[TestDox('Signatário do modelo com o campo vazio: nome "____________" (L372), e-mail vazio; nome só de sublinhados conta como vazio')]
    public function testSignatarioDoModeloComCampoVazio(): void
    {
        $entrar = $this->funcao('entrarSign');
        self::assertStringContainsString('nome: res(x.nome, w.c).trim()', $entrar, 'a linha do desenho no nome');
        self::assertStringContainsString("email: res(x.email, w.c, 'vazio').trim()", $entrar, 'o e-mail inválido travaria até o rascunho');
        self::assertStringContainsString("return modo === 'vazio' ? '' : modo ? '\\u0001____________\\u0002' : '____________';", $this->funcao('res'));

        self::assertStringContainsString(
            "if (w.sign.some(x => !String(x.nome || '').replace(/_/g, '').trim())) { erroW(T('p3.nomes')); return; }",
            $this->js(),
        );
    }

    #[TestDox('"Alterar logo" aceita qualquer imagem (desenho L180); o canvas a converte em PNG')]
    public function testLogoAceitaQualquerImagem(): void
    {
        self::assertStringContainsString('<input type="file" accept="image/*" data-bja-logo hidden>', $this->funcao('passo2'));
        self::assertStringContainsString("c.toBlob(b => {", $this->funcao('subirLogo'));
        self::assertStringContainsString("'image/png'", $this->funcao('subirLogo'));
    }

    #[TestDox('CSS do desenho: os rótulos da logo e da ordem sem mãozinha (L180, L189); a seleção com 30px também no celular (L149)')]
    public function testCssDoAssistenteEDaLista(): void
    {
        $css = (string) file_get_contents(self::APP . '/public/css/assinatura.css');

        foreach (['.bja .bjaLogoFix {', '.bja .bjaOrdem {'] as $seletor) {
            $inicio = strpos($css, $seletor);
            self::assertNotFalse($inicio, $seletor);
            $regra = substr($css, $inicio, (int) strpos($css, '}', $inicio) - $inicio);
            self::assertStringNotContainsString('cursor', $regra, $seletor);
        }
        self::assertStringContainsString('.bja .bjaTdSel { width: 30px; }', $css);
        self::assertStringNotContainsString('td.bjaTdSel { width: auto; }', $css, 'o @media não muda a largura da seleção');
    }
}
