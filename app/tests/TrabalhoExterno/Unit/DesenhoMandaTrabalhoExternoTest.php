<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Onde a tela divergia do desenho 06 (25/10), o desenho manda. O JS é lido como folha (sem navegador no PHPUnit).
 */
final class DesenhoMandaTrabalhoExternoTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/trabalho-externo.js');
    }

    private function funcao(string $assinatura): string
    {
        $js = $this->js();
        $ini = strpos($js, $assinatura);
        self::assertNotFalse($ini, $assinatura);
        $fim = strpos($js, "\n    }\n", $ini);
        self::assertNotFalse($fim);

        return substr($js, $ini, $fim - $ini);
    }

    #[TestDox('"Apagar para todos" usa confirm() nativo com o texto do desenho (l.1334), sem modal próprio')]
    public function testApagarParaTodosUsaConfirmNativo(): void
    {
        $f = $this->funcao('    function apagarParaTodos(');
        self::assertStringContainsString("window.confirm('Apagar esta mensagem? Ela some para todos e fica registrado que foi apagada.')", $f);
        self::assertStringNotContainsString('abrirConf', $f);
        self::assertMatchesRegularExpression('/if \(!window\.confirm\([^\n]+\) \{\s+return;/', $f);
    }

    #[TestDox('"Não aprovar" usa prompt() nativo (l.1076): vazio vale, cancelar aborta')]
    public function testNaoAprovarUsaPromptNativo(): void
    {
        $f = $this->funcao('    function acaoVaga(');
        self::assertStringContainsString("window.prompt('Motivo para não publicar (o solicitante verá):', '')", $f);
        self::assertStringContainsString('if (motivo === null)', $f);
        self::assertStringNotContainsString('abrirConf', $f);
    }

    #[TestDox('O item "Apagar para todos" do menu não leva o selo "sem prazo"; o item travado mantém o texto do servidor')]
    public function testMenuSemSelo(): void
    {
        $js = $this->js();
        self::assertStringNotContainsString("com-selo-sem-prazo' : ''), seloSemPrazo(m));", $js);
        self::assertStringContainsString("item('Apagar para todos', 'bi-trash-fill', function () { apagarParaTodos(m); }, 'is-perigo');", $js);
        self::assertStringContainsString("item(m.apagarBloqueado, 'bi-lock', function () {}, 'is-travado');", $js);
    }

    #[TestDox('"Disponível por" só oferece 5, 10, 15, 30 e 60 min; 120 e 1440 antigos caem no padrão de 15, sem erro')]
    public function testDuracoesDaOferta(): void
    {
        self::assertSame([5, 10, 15, 30, 60], DadosDaVaga::DURACOES_DA_OFERTA);
        foreach ([120, 1440, 7, 'x', null] as $antigo) {
            self::assertSame(15, DadosDaVaga::deArray(['duracaoDaOferta' => $antigo])->duracaoDaOferta);
        }
        self::assertSame(30, DadosDaVaga::deArray(['duracaoDaOferta' => 30])->duracaoDaOferta);
        self::assertStringContainsString('var DURACOES_OFERTA = [5, 10, 15, 30, 60];', $this->js());
        self::assertStringContainsString('duracaoValida(+X.duracaoDaOferta)', $this->js());
    }

    #[TestDox('A pré-visualização do currículo mostra sempre "Baixar PDF"; sem pdfUrl imprime o documento')]
    public function testBaixarPdfSempre(): void
    {
        $f = $this->funcao('    function renderDocCv(') ;
        self::assertStringContainsString("botaoTlm('Baixar PDF', 'bi-file-earmark-pdf', 'pri', function () { baixarPdfDoCv(X); })", $this->js());
        self::assertStringNotContainsString('if (!vazio(X.pdfUrl))', $f);
        $b = $this->funcao('    function baixarPdfDoCv(');
        self::assertStringContainsString('baixar(X.pdfUrl)', $b);
        self::assertStringContainsString('w.print()', $b);
    }
}
