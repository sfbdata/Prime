<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\ContadorDePaginasPorGhostscript;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A contagem de páginas pelo Ghostscript (D1): um PDF real de N páginas devolve N; o que não é
 * PDF, o que não existe e o binário que não sobe devolvem NULL — nunca exceção, porque a
 * contagem é opcional e roda dentro do upload.
 *
 * Os casos com o binário real são pulados onde ele não existe (o container tem o gs 10.05).
 */
#[CoversClass(ContadorDePaginasPorGhostscript::class)]
final class ContadorDePaginasPorGhostscriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contador-paginas-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function saidas(): iterable
    {
        yield 'só o número'            => ["3\n", 3];
        yield 'aviso antes do número'  => ["Warning: fonte substituída\n12\n", 12];
        yield 'vazio'                  => ['', null];
        yield 'zero não é contagem'    => ["0\n", null];
        yield 'texto'                  => ["Error: /undefined in pdfpagecount\n", null];
        yield 'número no meio do texto' => ["File has 7 pages.\n", null];
    }

    #[TestDox('a saída do gs vira contagem pela ÚLTIMA linha só com dígitos: $_dataName')]
    #[DataProvider('saidas')]
    public function testLeituraDaSaida(string $saida, ?int $esperado): void
    {
        self::assertSame($esperado, ContadorDePaginasPorGhostscript::ultimoInteiroPositivo($saida));
    }

    #[TestDox('binário que não existe: NULL, sem exceção')]
    public function testBinarioAusenteDevolveNull(): void
    {
        $pdf = $this->dir . '/qualquer.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");

        $contador = new ContadorDePaginasPorGhostscript($this->dir . '/gs-que-nao-existe', new NullLogger(), 5.0);

        self::assertNull($contador->contar($pdf));
    }

    #[TestDox('arquivo que não existe: NULL, sem chamar o gs')]
    public function testArquivoAusenteDevolveNull(): void
    {
        $contador = new ContadorDePaginasPorGhostscript($this->dir . '/gs-que-nao-existe', new NullLogger(), 5.0);

        self::assertNull($contador->contar($this->dir . '/nao-existe.pdf'));
    }

    #[TestDox('PDF real de 3 páginas: 3')]
    public function testContaAsPaginasDeUmPdfReal(): void
    {
        $this->exigirGhostscript();

        $pdf = $this->dir . '/tres.pdf';
        GhostscriptDeTeste::pdfReal($pdf, 3);

        self::assertSame(3, $this->contador()->contar($pdf));
    }

    #[TestDox('PDF real de 1 página: 1 (o mínimo que a entidade aceita)')]
    public function testContaUmaPagina(): void
    {
        $this->exigirGhostscript();

        $pdf = $this->dir . '/uma.pdf';
        GhostscriptDeTeste::pdfReal($pdf, 1);

        self::assertSame(1, $this->contador()->contar($pdf));
    }

    #[TestDox('arquivo que não é PDF (texto): NULL')]
    public function testTextoNaoEhContado(): void
    {
        $this->exigirGhostscript();

        $texto = $this->dir . '/nota.txt';
        file_put_contents($texto, "isto não é um PDF\n");

        self::assertNull($this->contador()->contar($texto));
    }

    #[TestDox('PDF truncado: NULL, não zero')]
    public function testPdfTruncadoNaoEhContado(): void
    {
        $this->exigirGhostscript();

        $pdf = $this->dir . '/truncado.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n");

        self::assertNull($this->contador()->contar($pdf));
    }

    #[TestDox('caminho com parênteses não quebra a string PostScript')]
    public function testCaminhoComParenteses(): void
    {
        $this->exigirGhostscript();

        $pdf = $this->dir . '/peça (final).pdf';
        GhostscriptDeTeste::pdfReal($pdf, 2);

        self::assertSame(2, $this->contador()->contar($pdf));
    }

    private function contador(): ContadorDePaginasPorGhostscript
    {
        return new ContadorDePaginasPorGhostscript(GhostscriptDeTeste::binario(), new NullLogger(), 30.0);
    }

    private function exigirGhostscript(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }
    }
}
