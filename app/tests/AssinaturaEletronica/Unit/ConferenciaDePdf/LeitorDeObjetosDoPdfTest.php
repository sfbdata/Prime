<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\InfladorLimitado;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LeitorDeObjetosDoPdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeObjetosDoPdf::class)]
#[CoversClass(InfladorLimitado::class)]
final class LeitorDeObjetosDoPdfTest extends TestCase
{
    private LeitorDeObjetosDoPdf $leitor;

    protected function setUp(): void
    {
        $this->leitor = new LeitorDeObjetosDoPdf();
    }

    #[TestDox('objeto solto: vale a última versão (depois do acréscimo incremental)')]
    public function testObjetoSolto(): void
    {
        $primeira = OficinaDePdfAssinado::pdfAssinado();
        $segunda = OficinaDePdfAssinado::acrescimoParaAssinar($primeira);

        self::assertSame('<</Type/Pages/Kids[3 0 R]/Count 1>>', $this->leitor->objeto($primeira, 2, 0));
        self::assertSame('<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R]>>', $this->leitor->objeto($primeira, 3, 0));
        self::assertSame('<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R 6 0 R]>>', $this->leitor->objeto($segunda, 3, 0));
        self::assertNull($this->leitor->objeto($primeira, 3, 1), 'outra geração');
        self::assertNull($this->leitor->objeto($primeira, 33, 0), 'o 33 não é o 3');
    }

    /** @return iterable<string, array{bool}> */
    public static function compressoes(): iterable
    {
        yield 'sem compressão' => [false];
        yield 'com FlateDecode' => [true];
    }

    #[DataProvider('compressoes')]
    #[TestDox('objeto dentro de /ObjStm $_dataName')]
    public function testObjetoEmFluxo(bool $comprimido): void
    {
        $pdf = self::pdfComFluxo(['<</Type/Page/Annots[4 0 R]>>', '<</Type/Annot/Subtype/Widget>>'], $comprimido);

        self::assertSame('<</Type/Page/Annots[4 0 R]>>', $this->leitor->objeto($pdf, 10, 0));
        self::assertSame('<</Type/Annot/Subtype/Widget>>', $this->leitor->objeto($pdf, 11, 0));
        self::assertNull($this->leitor->objeto($pdf, 12, 0));
    }

    /** @return iterable<string, array{string}> */
    public static function fluxosRuins(): iterable
    {
        $bomba = (string) gzcompress(str_repeat('0', LimitesDaConferencia::INFLADO_MAXIMO_EM_BYTES + 1));
        yield 'bomba de compressão' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/N 1/First 5/Filter/FlateDecode/Length 1>>\nstream\n" . $bomba . "\nendstream\nendobj\n"];
        yield 'sem /N' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/First 5>>\nstream\n10 0 <<>>\nendstream\nendobj\n"];
        yield '/N acima do limite' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/N 10001/First 5>>\nstream\n10 0 <<>>\nendstream\nendobj\n"];
        yield 'sem endstream' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/N 1/First 5>>\nstream\n10 0 <<>>"];
        yield '/First depois do fim' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/N 1/First 999>>\nstream\n10 0 <<>>\nendstream\nendobj\n"];
        yield 'FlateDecode com lixo' => ["%PDF-1.7\n5 0 obj\n<</Type/ObjStm/N 1/First 5/Filter/FlateDecode>>\nstream\nnão é zlib\nendstream\nendobj\n"];
    }

    #[DataProvider('fluxosRuins')]
    #[TestDox('/ObjStm ruim ($_dataName) devolve null, sem exceção')]
    public function testFluxoRuim(string $pdf): void
    {
        self::assertNull($this->leitor->objeto($pdf, 10, 0));
    }

    #[TestDox('limite de cabeçalhos "N G obj": 10.000 lê o último; 10.001 devolve null')]
    public function testLimiteDeCabecalhos(): void
    {
        $limite = LimitesDaConferencia::OBJETOS_MAXIMOS;
        $pdf = static fn (int $vezes): string => "%PDF-1.7\n" . str_repeat("10 0 obj\n<</V 1>>\nendobj\n", $vezes - 1) . "10 0 obj\n<</V 2>>\nendobj\n";

        self::assertSame('<</V 2>>', $this->leitor->objeto($pdf($limite), 10, 0));
        self::assertNull($this->leitor->objeto($pdf($limite + 1), 10, 0));
    }

    #[TestDox('limite de /ObjStm: 10.000 fluxos lê o objeto; 10.001 devolve null')]
    public function testLimiteDeFluxos(): void
    {
        $limite = LimitesDaConferencia::OBJETOS_MAXIMOS;
        $fluxo = static fn (int $numero, string $objeto): string => $numero . " 0 obj\n<</Type/ObjStm/N 1/First 6>>\nstream\n" . $objeto . "\nendstream\nendobj\n";
        $pdf = static function (int $vezes) use ($fluxo): string {
            $corpo = "%PDF-1.7\n";
            for ($i = 1; $i < $vezes; ++$i) {
                $corpo .= $fluxo(100 + $i, '20 0  <</V 1>>');
            }

            return $corpo . $fluxo(100 + $vezes, '10 0  <</V 2>>');
        };

        self::assertSame('<</V 2>>', $this->leitor->objeto($pdf($limite), 10, 0));
        self::assertNull($this->leitor->objeto($pdf($limite + 1), 10, 0));
    }

    #[TestDox('número negativo e PDF acima de 20 MB devolvem null')]
    public function testEntradasForaDoLimite(): void
    {
        self::assertNull($this->leitor->objeto(OficinaDePdfAssinado::pdfSemAssinatura(), -1, 0));
        self::assertNull($this->leitor->objeto(str_repeat(' ', LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1), 1, 0));
    }

    #[TestDox('inflar: zlib válido sai inteiro; acima do limite, lixo e cortado devolvem null')]
    public function testInflar(): void
    {
        $texto = str_repeat('BlueJus ', 5000);
        $comprimido = (string) gzcompress($texto);

        self::assertSame($texto, InfladorLimitado::inflar($comprimido, \strlen($texto)));
        self::assertNull(InfladorLimitado::inflar($comprimido, \strlen($texto) - 1));
        self::assertNull(InfladorLimitado::inflar('não é zlib', 1000));
        self::assertNull(InfladorLimitado::inflar(substr($comprimido, 0, -10), 100_000));
        self::assertSame('', InfladorLimitado::inflar((string) gzcompress(''), 10));
    }

    #[TestDox('inflar bomba de 10 MB com teto de 1 MB para cedo, sem guardar a saída inteira')]
    public function testBombaParaCedo(): void
    {
        $bomba = (string) gzcompress(str_repeat("\0", 10 * 1024 * 1024), 9);
        memory_reset_peak_usage();
        $antes = memory_get_usage();

        self::assertNull(InfladorLimitado::inflar($bomba, 1024 * 1024));
        self::assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $antes);
    }

    /** @param list<string> $corpos objetos 10, 11, ... */
    private static function pdfComFluxo(array $corpos, bool $comprimido): string
    {
        $cabecalho = '';
        $dados = '';
        foreach ($corpos as $i => $corpo) {
            $cabecalho .= (10 + $i) . ' ' . \strlen($dados) . ' ';
            $dados .= $corpo . "\n";
        }
        $fluxo = $cabecalho . $dados;
        $primeiro = \strlen($cabecalho);
        $filtro = '';
        if ($comprimido) {
            $fluxo = (string) gzcompress($fluxo);
            $filtro = '/Filter/FlateDecode';
        }

        return "%PDF-1.7\n1 0 obj\n<</Type/Catalog>>\nendobj\n5 0 obj\n<</Type/ObjStm/N " . \count($corpos) . '/First ' . $primeiro . $filtro
            . '/Length ' . \strlen($fluxo) . ">>\nstream\n" . $fluxo . "\nendstream\nendobj\n";
    }
}
