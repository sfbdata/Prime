<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\SanitizadorTextoRico;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-009 (docs/specs/engineering-loop/inventario-editor.md): "Citar no novo registro" no ⋮ da
 * observação (dc L.6460/6466: `bj-editor-inserir` com `<blockquote>…</blockquote><p><br></p>` e o
 * aviso "Citação inserida no editor").
 *
 * Teste de FOLHA (lê o JS e o template; sem navegador) + o sanitizador real: o que a citação
 * produz (`<blockquote>`) sobrevive ao salvar, e o trecho citado não entra no editor como HTML cru.
 * O arranjo do item no ⋮ é do `PastaFidelidadeRestanteTelaTest` (functional).
 */
#[CoversClass(SanitizadorTextoRico::class)]
final class EditorRicoCitacaoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const APP = __DIR__ . '/../../..';
    private const JS = self::APP . '/public/js/editor-rico.js';
    private const DETALHES = self::APP . '/templates/pasta/_detalhes_obs.html.twig';

    private function ler(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        self::assertIsString($conteudo, "não leu {$caminho}");

        return $conteudo;
    }

    /** O corpo de `function $nome(…) {` até o fechamento no recuo de 4 espaços. */
    private function funcao(string $fonte, string $nome): string
    {
        $ini = strpos($fonte, '    function ' . $nome . '(');
        self::assertNotFalse($ini, "função {$nome} não encontrada");
        $fim = strpos($fonte, "\n    }", $ini);

        return substr($fonte, $ini, $fim - $ini);
    }

    #[TestDox('o trecho citado passa pelo conversor da colagem e vira formato de linha blockquote')]
    public function testCitacaoPassaPeloConversorDoEditor(): void
    {
        $js = $this->ler(self::JS);
        $delta = $this->funcao($js, 'deltaDaCitacao');

        self::assertStringContainsString("quill.clipboard.convert({ html: String(html || ''), text: '' })", $delta);
        self::assertStringContainsString("{ blockquote: true }", $delta);
        // Só os formatos de trecho do editor acompanham o texto citado.
        self::assertStringContainsString('FORMATOS_DE_TRECHO.forEach', $delta);
        self::assertStringContainsString('inserirCitacao: inserirCitacao,', $js);
    }

    #[TestDox('a citação entra pelo caminho do usuário (vale o limite), sem innerHTML, e avisa como o desenho')]
    public function testCitacaoEntraComoDoUsuarioEAvisa(): void
    {
        $inserir = $this->funcao($this->ler(self::JS), 'inserirCitacao');

        self::assertStringContainsString("quill.updateContents(mudanca, 'user')", $inserir);
        self::assertStringNotContainsString('innerHTML', $inserir);
        self::assertStringNotContainsString('dangerouslyPasteHTML', $inserir);
        self::assertStringContainsString("'Citação inserida no editor'", $inserir);
        self::assertStringContainsString("textoDoChip('bi-quote'", $inserir);
        self::assertStringContainsString('scrollIntoView', $inserir, 'precisa rolar até o compositor');
    }

    #[TestDox('o ⋮ da observação cita no compositor de observações, lendo o texto já sanitizado da tela')]
    public function testMenuDaObservacaoCitaNoCompositor(): void
    {
        $fonte = $this->ler(self::DETALHES);
        $citar = $this->funcao($fonte, 'citarObs');

        self::assertStringContainsString(".querySelector('.obs-conteudo-det .ql-editor')", $citar);
        self::assertStringContainsString('EditorRico.inserirCitacao(obsConteudo, corpo.innerHTML)', $citar);
        self::assertDoesNotMatchRegularExpression('/innerHTML\s*=/', $citar, 'citar não escreve HTML na tela');
        self::assertStringContainsString("e.target.closest('.js-obs-citar')", $fonte);
    }

    #[TestDox('o que a citação produz sobrevive ao sanitizador do servidor')]
    public function testBlockquoteSobreviveAoSanitizador(): void
    {
        $limpo = (string) $this->criarSanitizadorTextoRico()->limpar(
            '<blockquote>Relato do <strong>cliente</strong>.</blockquote><blockquote>Segunda linha</blockquote><p><br></p>'
        );

        self::assertStringContainsString('<blockquote>Relato do <strong>cliente</strong>.</blockquote>', $limpo);
        self::assertStringContainsString('<blockquote>Segunda linha</blockquote>', $limpo);
    }
}
