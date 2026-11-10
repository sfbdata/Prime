<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os botões de PDF da barra da folha (E16 do desenho 16; `docs/specs/ponto-desenho-0811.md`, P87 a P89; decisões 2 e
 * 12) moram em `public/js/ponto-folha.js`. Cada pedido à rota do PDF é uma emissão nova, com código BJF próprio
 * (decisão 2): pedido que sobra vira código que ninguém recebeu. O PHPUnit não executa script (o container não tem
 * node): estes testes travam no texto as escolhas de que a tela depende (o smoke no navegador é do dono).
 */
#[Group('ponto')]
#[CoversNothing]
final class PontoFolhaJsTest extends TestCase
{
    #[TestDox('O "Baixar" de dentro do "Ver PDF" salva o mesmo arquivo do quadro, sem novo pedido ao servidor')]
    public function testBaixarDaJanelaUsaOMesmoArquivo(): void
    {
        $js = self::folha();
        $baixar = self::corpo($js, 'function baixarDaJanela() {');

        self::assertStringContainsString('const pdf = ver.pdf;', $baixar, 'o PDF que já está no quadro');
        self::assertStringContainsString('salvar(pdf.blob, pdf.nome);', $baixar);
        self::assertStringContainsString('avisar(textoDaBaixa(pdf.codigo));', $baixar, 'o código da mesma emissão');
        self::assertStringNotContainsString('fetch(', $baixar);
        self::assertStringNotContainsString('pedirPdf(', $baixar);
        self::assertStringNotContainsString('comecarEmissao(', $baixar);

        self::assertSame(1, substr_count($js, 'fetch('), 'o único pedido ao servidor é o do pedirPdf');
        self::assertStringContainsString('fetch(', self::corpo($js, 'async function pedirPdf(url, aborto) {'));
        self::assertSame(2, substr_count($js, 'await pedirPdf('), 'só o "Baixar PDF" e o "Ver PDF" pedem o PDF');
        self::assertStringContainsString('await pedirPdf(botao.dataset.folhaPdf, aborto);', self::corpo($js, 'async function baixarPdf(botao) {'));
        self::assertStringContainsString('await pedirPdf(botao.dataset.folhaVer, aborto);', self::corpo($js, 'async function abrirVer(botao) {'));
        self::assertStringContainsString("['[data-folha-ver-baixar]', baixarDaJanela]", $js);
    }

    #[TestDox('"Baixar PDF" e "Ver PDF" dividem a mesma trava: enquanto um gera, o outro não faz outro pedido')]
    public function testTravaUnicaDosDoisBotoes(): void
    {
        $js = self::folha();

        self::assertStringNotContainsString('gerandoPdf', $js, 'a trava antiga, só do "Baixar PDF", não volta');
        self::assertSame(1, substr_count($js, 'new AbortController()'), 'um controle só, o da trava');

        $comecar = self::corpo($js, 'function comecarEmissao() {');
        self::assertMatchesRegularExpression('/if \(emissao\) \{\s+return null;\s+\}\s+emissao = new AbortController\(\);\s+return emissao;/', $comecar);
        self::assertMatchesRegularExpression('/if \(emissao === aborto\) \{\s+emissao = null;\s+\}/', self::corpo($js, 'function terminarEmissao(aborto) {'), 'solta só a trava do próprio pedido');

        $baixar = self::corpo($js, 'async function baixarPdf(botao) {');
        self::assertMatchesRegularExpression('/const aborto = comecarEmissao\(\);\s+if \(!aborto\) \{\s+return;\s+\}/', $baixar);
        self::assertLessThan(strpos($baixar, 'avisar(GERANDO'), strpos($baixar, 'comecarEmissao()'), 'travado, nem o aviso azul aparece');
        self::assertLessThan(strpos($baixar, 'salvar('), strpos($baixar, 'terminarEmissao(aborto);'), 'a trava sai com a resposta');

        $abrir = self::corpo($js, 'async function abrirVer(botao) {');
        self::assertMatchesRegularExpression('/const aborto = comecarEmissao\(\);\s+if \(!aborto\) \{\s+return;\s+\}/', $abrir);
        self::assertLessThan(strpos($abrir, 'fundo.hidden = false;'), strpos($abrir, 'comecarEmissao()'), 'travado, a janela não abre');
        self::assertLessThan(strpos($abrir, 'soltarArquivo();'), strpos($abrir, 'comecarEmissao()'), 'travado, o arquivo do quadro não se perde');
        self::assertStringContainsString('terminarEmissao(aborto);', $abrir);
    }

    #[TestDox('Fechar o "Ver PDF" com o pedido em curso cancela o pedido, solta a trava e não mostra erro pelo cancelamento')]
    public function testFecharCancelaOPedido(): void
    {
        $js = self::folha();

        $fechar = self::corpo($js, 'function fecharVer() {');
        self::assertMatchesRegularExpression('/if \(ver\.aborto\) \{\s+ver\.aborto\.abort\(\);\s+terminarEmissao\(ver\.aborto\);\s+ver\.aborto = null;\s+\}/', $fechar);
        self::assertStringNotContainsString('avisar(', $fechar);

        $pedir = self::corpo($js, 'async function pedirPdf(url, aborto) {');
        self::assertStringContainsString("fetch(url, { credentials: 'same-origin', signal: aborto.signal })", $pedir, 'o pedido ouve o controle da trava');
        self::assertStringContainsString('setTimeout(function () { venceu = true; aborto.abort(); }, PRAZO_PDF_MS);', $pedir);
        self::assertStringContainsString('return aborto.signal.aborted && !venceu ? { cancelado: true } : { erro: FALHOU };', $pedir, 'o prazo vencido é falha; fechar a janela é cancelamento');

        $abrir = self::corpo($js, 'async function abrirVer(botao) {');
        self::assertStringContainsString('ver.aborto = aborto;', $abrir);
        self::assertStringContainsString('if (pdf.cancelado || aborto !== ver.aborto || fundo.hidden) {', $abrir);
        self::assertLessThan(strpos($abrir, 'erroDaJanela(fundo, pdf.erro);'), strpos($abrir, 'pdf.cancelado'), 'cancelado sai antes da linha de erro');
        self::assertLessThan(strpos($abrir, 'mostrarPdf(fundo, pdf);'), strpos($abrir, 'ver.aborto = null;'), 'com o PDF no quadro, não há mais o que cancelar');
        self::assertStringNotContainsString('avisar(', $abrir, 'a falha do "Ver PDF" vai para a linha de erro da janela');

        $baixar = self::corpo($js, 'async function baixarPdf(botao) {');
        self::assertLessThan(strpos($baixar, 'avisar(pdf.erro'), strpos($baixar, 'if (pdf.cancelado) {'), 'cancelado nunca vira aviso vermelho');
    }

    #[TestDox('Todo endereço de arquivo criado no navegador é devolvido: o do baixar e o do quadro do "Ver PDF"')]
    public function testEnderecosDevolvidos(): void
    {
        $js = self::folha();

        self::assertSame(2, substr_count($js, 'URL.createObjectURL('), 'o do baixar e o do quadro');

        $salvar = self::corpo($js, 'function salvar(blob, nome) {');
        self::assertStringContainsString('const endereco = URL.createObjectURL(blob);', $salvar);
        self::assertStringContainsString('setTimeout(function () { URL.revokeObjectURL(endereco); }, 1000);', $salvar, 'depois do clique no link');

        self::assertStringContainsString('ver.endereco = URL.createObjectURL(pdf.blob);', self::corpo($js, 'function mostrarPdf(fundo, pdf) {'));
        $soltar = self::corpo($js, 'function soltarArquivo() {');
        self::assertMatchesRegularExpression('/if \(ver\.endereco\) \{\s+URL\.revokeObjectURL\(ver\.endereco\);\s+\}\s+ver\.endereco = \'\';\s+ver\.pdf = null;/', $soltar);
        self::assertStringContainsString('soltarArquivo();', self::corpo($js, 'function fecharVer() {'), 'fechar devolve o do quadro');
        self::assertStringContainsString('soltarArquivo();', self::corpo($js, 'async function abrirVer(botao) {'), 'abrir de novo devolve o anterior');
    }

    #[TestDox('Só resposta "application/pdf" vira arquivo: a tela de entrar devolvida pela sessão caída é falha, conferida antes de ler o corpo')]
    public function testSoPdfViraArquivo(): void
    {
        $pedir = self::corpo(self::folha(), 'async function pedirPdf(url, aborto) {');

        self::assertMatchesRegularExpression(
            "/if \\(!resposta\\.ok \\|\\| !\\(resposta\\.headers\\.get\\('Content-Type'\\) \\|\\| ''\\)\\.includes\\('application\\/pdf'\\)\\) \\{\\s+return \\{ erro: FALHOU \\};\\s+\\}/",
            $pedir
        );
        self::assertLessThan(strpos($pedir, 'await resposta.blob()'), strpos($pedir, "includes('application/pdf')"), 'confere antes de ler o arquivo');
        self::assertLessThan(strpos($pedir, "includes('application/pdf')"), strpos($pedir, 'resposta.status === 429'), 'o limite tem frase própria');
    }

    #[TestDox('Sem travessão no script da folha')]
    public function testSemTravessao(): void
    {
        self::assertDoesNotMatchRegularExpression('/[—–]/u', self::folha());
    }

    private static function folha(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/ponto-folha.js');
    }

    /** Corpo de uma função, delimitado por contagem de chaves a partir da assinatura. */
    private static function corpo(string $fonte, string $assinatura): string
    {
        $inicio = strpos($fonte, $assinatura);
        self::assertIsInt($inicio, 'faltou: ' . $assinatura);
        $abre = strpos($fonte, '{', $inicio);
        self::assertIsInt($abre);

        $profundidade = 0;
        for ($i = $abre, $n = strlen($fonte); $i < $n; $i++) {
            if ($fonte[$i] === '{') {
                $profundidade++;
            } elseif ($fonte[$i] === '}' && --$profundidade === 0) {
                return substr($fonte, $inicio, $i - $inicio + 1);
            }
        }

        self::fail('o corpo não fecha: ' . $assinatura);
    }
}
