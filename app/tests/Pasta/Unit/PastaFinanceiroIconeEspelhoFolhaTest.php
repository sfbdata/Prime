<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Twig\ArquivoIconeExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O Financeiro redesenha o ícone do arquivo no navegador (upload e renomear) com um
 * espelho JS de `arquivo_icone()`. Lido como FOLHA (não há navegador no PHPUnit): o
 * espelho tem de devolver, para cada tipo que o upload aceita e para cada desempate
 * pelo MIME, exatamente o ícone, o tipo e o rótulo do helper PHP, e pintar pela
 * classe `ps-doc-icone--<tipo>` (nunca inline). Mudou lá, muda aqui; este teste avisa.
 */
#[CoversNothing]
final class PastaFinanceiroIconeEspelhoFolhaTest extends TestCase
{
    private const FINANCEIRO = __DIR__ . '/../../../templates/pasta/_financeiro.html.twig';

    private ArquivoIconeExtension $helper;
    private string $fonte;

    protected function setUp(): void
    {
        $this->helper = new ArquivoIconeExtension();
        $fonte        = file_get_contents(self::FINANCEIRO);
        self::assertIsString($fonte);
        $this->fonte = $fonte;
    }

    /** O corpo de `function nome(` até a próxima `function ` no mesmo nível de indentação. */
    private function funcao(string $nome): string
    {
        $inicio = strpos($this->fonte, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, $nome . ' existe');
        $fim = strpos($this->fonte, "\n    function ", $inicio + 1);
        self::assertNotFalse($fim);

        return substr($this->fonte, $inicio, $fim - $inicio);
    }

    /** `['ícone', 'tipo', 'rótulo']` como o helper devolve para este nome/MIME, escapado para regex. */
    private function literal(string $nome, string $mime = ''): string
    {
        $e = $this->helper->arquivoIcone($nome, $mime);

        return preg_quote(sprintf("['%s', '%s', '%s']", $e['icone'], $e['tipo'], $e['rotulo']), '/');
    }

    #[TestDox('pela extensão, o espelho JS devolve o mesmo ícone, tipo e rótulo do helper')]
    public function testEspelhoPorExtensaoBateComOHelper(): void
    {
        $js = $this->funcao('iconeDoArquivo');

        foreach (['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'] as $ext) {
            $e = $this->helper->arquivoIcone('arquivo.' . $ext);
            self::assertMatchesRegularExpression(
                sprintf("/\\b%s:\\s*\\['%s',\\s*'%s',\\s*'%s'\\]/", $ext, preg_quote($e['icone'], '/'), $e['tipo'], $e['rotulo']),
                $js,
                "o espelho JS para .{$ext} bate com o helper ({$e['icone']}, {$e['tipo']}, {$e['rotulo']})"
            );
        }
    }

    /**
     * Cada literal preso à SUA condição: só conferir que o literal existe deixaria
     * passar um ramo trocado (o de imagem repete o das extensões png/jpg).
     */
    #[TestDox('pelo MIME, cada desempate do espelho JS devolve o que o helper devolve')]
    public function testEspelhoPorMimeBateComOHelper(): void
    {
        $js = $this->funcao('iconeDoArquivo');

        $ramos = [
            'image/webp'               => "m\\.startsWith\\('image\\/'\\)\\s*\\?\\s*",
            'application/pdf'          => "m\\.includes\\('pdf'\\)\\s*\\?\\s*",
            'application/msword'       => "\\(m\\.includes\\('word'\\)\\s*\\|\\|\\s*m\\.includes\\('document'\\)\\)\\s*\\?\\s*",
            'application/octet-stream' => ":\\s*",
        ];
        foreach ($ramos as $mime => $condicao) {
            self::assertMatchesRegularExpression(
                '/' . $condicao . $this->literal('sem-ext', $mime) . '/',
                $js,
                "o ramo de {$mime} devolve o que o helper devolve"
            );
        }
        self::assertStringContainsString("'Arquivo']);", $js, 'o genérico fecha a cadeia de desempates');
    }

    #[TestDox('o espelho pinta pela classe do tipo, nunca com cor inline, e sem a família antiga')]
    public function testEspelhoPintaPelaClasseDoTipo(): void
    {
        $html = $this->funcao('htmlIconeDoArquivo');

        self::assertStringContainsString("'<span class=\"ps-doc-icone ps-doc-icone--' + esc(ic.tipo) + '\"", $html);
        self::assertStringNotContainsString('style="color', $html, 'cor inline ignoraria o par escuro dos tokens');
        self::assertStringNotContainsString('bi-filetype-', $this->funcao('iconeDoArquivo'), 'a família antiga (contorno) saiu do espelho');
    }
}
