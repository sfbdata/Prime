<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\ValidadorDeArquivoComprimido;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * D27 — "menor" nunca é prova de que a compressão deu certo. A saída só substitui o original se
 * for um arquivo do mesmo tipo, legível, com as mesmas páginas (PDF) ou dimensões (imagem).
 *
 * Os casos de PDF truncado vêm de uma medição no container: o Ghostscript 10.05 relê um PDF cuja
 * escrita foi interrompida e sai com código 0 sem processar página nenhuma — o código de saída,
 * sozinho, aprovaria o arquivo.
 */
#[CoversClass(ValidadorDeArquivoComprimido::class)]
final class ValidadorDeArquivoComprimidoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $this->dir = sys_get_temp_dir() . '/validador-compressao-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function validador(?string $binario = null, int $timeout = 120): ValidadorDeArquivoComprimido
    {
        return new ValidadorDeArquivoComprimido($binario ?? GhostscriptDeTeste::BINARIO_REAL, $timeout);
    }

    private function pdf(int $paginas): string
    {
        $caminho = $this->dir . '/valido-' . $paginas . '-' . bin2hex(random_bytes(3)) . '.pdf';
        GhostscriptDeTeste::pdfReal($caminho, $paginas);

        return $caminho;
    }

    private function arquivo(string $nome, string $conteudo): string
    {
        $caminho = $this->dir . '/' . $nome;
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }

    #[TestDox('PDF válido com as páginas esperadas é aceito')]
    public function testPdfValidoEhAceito(): void
    {
        self::assertTrue($this->validador()->pdfValido($this->pdf(3), 3));
    }

    #[TestDox('PDF válido com OUTRA contagem de páginas é recusado')]
    public function testPdfComOutraContagemEhRecusado(): void
    {
        $pdf = $this->pdf(3);

        self::assertFalse($this->validador()->pdfValido($pdf, 4));
        self::assertFalse($this->validador()->pdfValido($pdf, 1));
    }

    /** @return iterable<string, array{\Closure(string): string}> */
    public static function pdfsEstragados(): iterable
    {
        yield 'truncado no começo (300 bytes)' => [static fn (string $pdf): string => substr($pdf, 0, 300)];
        yield 'truncado no fim (sem xref)' => [static fn (string $pdf): string => substr($pdf, 0, -200)];
        yield 'corrompido no meio' => [static fn (string $pdf): string => substr_replace($pdf, str_repeat('X', 40), 400, 40)];
        yield 'só o cabeçalho' => [static fn (string $pdf): string => "%PDF-1.4\nlixo qualquer\n%%EOF\n"];
        yield 'lixo antes do cabeçalho' => [static fn (string $pdf): string => 'LIXO' . $pdf];
        yield 'vazio' => [static fn (string $pdf): string => ''];
    }

    /** @param \Closure(string): string $estragar */
    #[DataProvider('pdfsEstragados')]
    #[TestDox('PDF estragado ($_dataName) é recusado')]
    public function testPdfEstragadoEhRecusado(\Closure $estragar): void
    {
        $bom       = (string) file_get_contents($this->pdf(3));
        $estragado = $this->arquivo('estragado.pdf', $estragar($bom));

        self::assertFalse($this->validador()->pdfValido($estragado, 3));
    }

    /**
     * Defeitos que o próprio Ghostscript 10.05 aceita — relê o arquivo, sai com código 0 e anuncia
     * as 3 páginas. Só as checagens do validador os recusam, e o controle de cada caso prova isso:
     * se uma versão futura do Ghostscript passar a recusá-los, o controle cai e avisa.
     *
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function defeitosQueOGhostscriptTolera(): iterable
    {
        yield 'quebra de linha antes do cabeçalho' => [static fn (string $pdf): string => "\n" . $pdf];
        yield 'sem o %%EOF final' => [static fn (string $pdf): string => substr($pdf, 0, (int) strrpos($pdf, '%%EOF'))];
    }

    /** @param \Closure(string): string $estragar */
    #[DataProvider('defeitosQueOGhostscriptTolera')]
    #[TestDox('PDF com defeito que o Ghostscript tolera ($_dataName) é recusado pelo validador')]
    public function testDefeitoQueOGhostscriptToleraEhRecusado(\Closure $estragar): void
    {
        $estragado = $this->arquivo('tolerado.pdf', $estragar((string) file_get_contents($this->pdf(3))));

        $releitura = new Process([
            GhostscriptDeTeste::BINARIO_REAL, '-dBATCH', '-dNOPAUSE', '-dSAFER', '-dPDFSTOPONERROR', '-sDEVICE=nullpage', $estragado,
        ]);
        $releitura->run();
        self::assertTrue($releitura->isSuccessful(), 'controle: o Ghostscript passou a recusar este caso sozinho');
        self::assertSame(3, ValidadorDeArquivoComprimido::paginasProcessadas($releitura->getOutput()), 'controle: o Ghostscript não leu as 3 páginas');

        self::assertFalse($this->validador()->pdfValido($estragado, 3));
    }

    #[TestDox('PDF inexistente é recusado, sem exceção')]
    public function testPdfInexistenteEhRecusado(): void
    {
        self::assertFalse($this->validador()->pdfValido($this->dir . '/nao-existe.pdf', 1));
    }

    #[TestDox('Ghostscript ausente: a validação recusa, sem exceção')]
    public function testGhostscriptAusenteRecusa(): void
    {
        self::assertFalse($this->validador('/inexistente/gs')->pdfValido($this->pdf(1), 1));
    }

    #[TestDox('releitura que estoura o tempo recusa, sem exceção')]
    public function testReleituraQueEstouraOTempoRecusa(): void
    {
        $lento = $this->arquivo('gs-lento', "#!/bin/sh\nsleep 5\n");
        chmod($lento, 0o700);
        $inicio = microtime(true);

        self::assertFalse($this->validador($lento, 1)->pdfValido($this->pdf(1), 1));
        self::assertLessThan(4.0, microtime(true) - $inicio, 'o timeout não foi respeitado');
    }

    #[TestDox('a contagem vem da linha "Processing pages 1 through N." — e só dela')]
    public function testPaginasProcessadas(): void
    {
        self::assertSame(12, ValidadorDeArquivoComprimido::paginasProcessadas("GPL Ghostscript\nProcessing pages 1 through 12.\nPage 1\n"));
        self::assertSame(1, ValidadorDeArquivoComprimido::paginasProcessadas('Processing pages 1 through 1.'));
        self::assertNull(ValidadorDeArquivoComprimido::paginasProcessadas("Page 1\nPage 2\n"));
        self::assertNull(ValidadorDeArquivoComprimido::paginasProcessadas(''));
        self::assertNull(ValidadorDeArquivoComprimido::paginasProcessadas('Processing pages 1 through 0.'));
        self::assertNull(
            ValidadorDeArquivoComprimido::paginasProcessadas("Processing pages 1 through 2.\nProcessing pages 1 through 3.\n"),
            'duas contagens diferentes na mesma saída não provam nada',
        );
    }

    #[TestDox('JPEG e PNG decodificáveis, no tipo e nas dimensões esperadas, são aceitos')]
    public function testImagemValidaEhAceita(): void
    {
        self::assertTrue($this->validador()->imagemValida($this->jpeg(40, 30), 'image/jpeg', 40, 30));
        self::assertTrue($this->validador()->imagemValida($this->png(40, 30), 'image/png', 40, 30));
    }

    #[TestDox('imagem com outras dimensões é recusada')]
    public function testImagemComOutrasDimensoesEhRecusada(): void
    {
        self::assertFalse($this->validador()->imagemValida($this->jpeg(40, 30), 'image/jpeg', 30, 40));
        self::assertFalse($this->validador()->imagemValida($this->png(40, 30), 'image/png', 41, 30));
    }

    #[TestDox('imagem de outro tipo que o esperado é recusada')]
    public function testImagemDeOutroTipoEhRecusada(): void
    {
        self::assertFalse($this->validador()->imagemValida($this->png(40, 30), 'image/jpeg', 40, 30));
        self::assertFalse($this->validador()->imagemValida($this->jpeg(40, 30), 'image/png', 40, 30));
        self::assertFalse($this->validador()->imagemValida($this->jpeg(40, 30), 'image/gif', 40, 30));
    }

    /**
     * O GD decodifica JPEG truncado sem reclamar (`gd.jpeg_ignore_warning`), preenchendo o resto de
     * cinza: a decodificação sozinha aprovaria o arquivo. O fim da imagem tem de estar lá.
     */
    #[TestDox('JPEG ou PNG truncado é recusado, mesmo que o GD o abra')]
    public function testImagemTruncadaEhRecusada(): void
    {
        $jpeg = (string) file_get_contents($this->jpeg(80, 60));
        $png  = (string) file_get_contents($this->png(80, 60));

        self::assertFalse($this->validador()->imagemValida($this->arquivo('t.jpg', substr($jpeg, 0, -40)), 'image/jpeg', 80, 60));
        self::assertFalse($this->validador()->imagemValida($this->arquivo('t.png', substr($png, 0, -20)), 'image/png', 80, 60));
    }

    #[TestDox('lixo ou arquivo inexistente como imagem é recusado, sem aviso')]
    public function testLixoComoImagemEhRecusado(): void
    {
        self::assertFalse($this->validador()->imagemValida($this->arquivo('l.jpg', 'nao sou jpeg'), 'image/jpeg', 1, 1));
        self::assertFalse(
            $this->validador()->imagemValida($this->arquivo('l2.jpg', "nao sou jpeg\xFF\xD9"), 'image/jpeg', 1, 1),
            'terminar com o marcador de fim não basta: tem de decodificar',
        );
        self::assertFalse($this->validador()->imagemValida($this->dir . '/nada.png', 'image/png', 1, 1));
    }

    private function jpeg(int $largura, int $altura): string
    {
        $caminho = $this->dir . '/img-' . bin2hex(random_bytes(3)) . '.jpg';
        $imagem  = imagecreatetruecolor($largura, $altura);
        imagefilledrectangle($imagem, 0, 0, $largura - 1, $altura - 1, 0x336699);
        imagejpeg($imagem, $caminho, 90);

        return $caminho;
    }

    private function png(int $largura, int $altura): string
    {
        $caminho = $this->dir . '/img-' . bin2hex(random_bytes(3)) . '.png';
        $imagem  = imagecreatetruecolor($largura, $altura);
        imagefilledrectangle($imagem, 0, 0, $largura - 1, $altura - 1, 0x996633);
        imagepng($imagem, $caminho, 6);

        return $caminho;
    }
}
