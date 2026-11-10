<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Cor;
use App\AssinaturaEletronica\Pdf\EscritorDePdf;
use App\AssinaturaEletronica\Pdf\Fonte;
use App\AssinaturaEletronica\Pdf\MedidorDeTexto;
use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\PaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\RecursosDaFolha;
use App\AssinaturaEletronica\Pdf\Tela;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O PDF montado, conferido nos próprios bytes e nos pixels das JPEGs de cada página (em unidades do desenho:
 * folha de 1000 x 1414). As áreas amostradas não têm texto: o que muda nelas é só o fundo.
 */
#[CoversClass(MontadorDoPdf::class)]
final class MontadorDoPdfTest extends TestCase
{
    /** Abaixo da assinatura e acima do rodapé: só fundo (íris, ondas, rosácea e marca d'água). */
    private const AREA_SEM_TEXTO = [100.0, 900.0, 300.0, 200.0];
    /** Rodapé: o código vermelho começa em 92 + 116 + 24 = 232, com a base em 1414 - 172 + 42. */
    private const INICIO_DO_CODIGO = 232.0;
    private const BASE_DO_CODIGO = 1284.0;

    protected function setUp(): void
    {
        FolhaDeTeste::exigirFontes($this);
    }

    #[TestDox('PDF válido: cabeçalho, número de páginas (conteúdo mais as páginas de depois), título e produtor')]
    public function testPdfValido(): void
    {
        $folha = FolhaDeTeste::folha(mudancas: ['paginasDepois' => self::umaPaginaDepois('#c00000')]);

        $pdf = new PdfLido(self::montador()->montar($folha));

        self::assertStringStartsWith('%PDF-', $pdf->bytes);
        self::assertStringEndsWith("%%EOF\n", $pdf->bytes);
        self::assertSame(2, $pdf->paginas());
        self::assertSame('Documento BlueJus ' . FolhaDeTeste::CODIGO, $pdf->titulo());
        self::assertSame([2480, 3507], \array_slice(getimagesizefromstring($pdf->jpegs()[0]) ?: [], 0, 2));

        // A página de depois desenha no miolo e leva o rodapé por cima.
        $depois = $pdf->imagem(2);
        [$r, $g, $b] = PdfLido::media($depois, 300, 400, 200, 200);
        self::assertGreaterThan(150, $r - max($g, $b), 'O miolo da página de depois é o que a extensão pintou.');
        self::assertLessThan(60, PdfLido::maisEscuro($depois, 100, 1250, 110, 110), 'O QR do rodapé está na página de depois.');
    }

    #[TestDox('Folha branca: área sem texto branca, com cabeçalho e rodapé; sem ela, o fundo de segurança aparece')]
    public function testFolhaBrancaEFundo(): void
    {
        $branca = (new PdfLido(self::montador()->montar(FolhaDeTeste::folha(mudancas: ['folhaBranca' => true]))))->imagem(1);
        $fundo = (new PdfLido(self::montador()->montar(FolhaDeTeste::folha())))->imagem(1);

        foreach (PdfLido::media($branca, ...self::AREA_SEM_TEXTO) as $canal) {
            self::assertGreaterThan(253.5, $canal, 'Folha branca sem fundo.');
        }
        self::assertGreaterThan(250, PdfLido::maisEscuro($branca, ...self::AREA_SEM_TEXTO));

        [$r, $g, $b] = PdfLido::media($fundo, ...self::AREA_SEM_TEXTO);
        self::assertLessThan(251, min($r, $g, $b), 'O fundo íris tinge a página.');
        self::assertLessThan(235, PdfLido::maisEscuro($fundo, ...self::AREA_SEM_TEXTO), 'Ondas e rosácea aparecem.');

        // Cabeçalho (régua em 136) e QR do rodapé seguem na folha branca (E3-3).
        self::assertLessThan(90, PdfLido::maisEscuro($branca, 300, 135, 200, 3));
        self::assertLessThan(60, PdfLido::maisEscuro($branca, 100, 1250, 110, 110));
        imagedestroy($branca);
        imagedestroy($fundo);
    }

    #[TestDox('Rascunho imprime RASCUNHO no rodapé e no título do PDF')]
    public function testRascunho(): void
    {
        $rascunho = new PdfLido(self::montador()->montar(FolhaDeTeste::folha(mudancas: ['modo' => ModoDaFolha::Rascunho, 'codigo' => null])));
        $final = new PdfLido(self::montador()->montar(FolhaDeTeste::folha()));

        self::assertSame('Documento BlueJus RASCUNHO', $rascunho->titulo());
        self::assertEqualsWithDelta(self::fimDoCodigo('RASCUNHO'), self::fimDoVermelho($rascunho->imagem(1)), 4.0);
        self::assertEqualsWithDelta(self::fimDoCodigo(FolhaDeTeste::CODIGO), self::fimDoVermelho($final->imagem(1)), 4.0);
    }

    #[TestDox('PDF enviado de 2 páginas vira 2 páginas, cada uma com a sua página por dentro')]
    public function testPdfEnviado(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript ausente.');
        }
        $folha = FolhaDeTeste::folha([['k' => 'pg', 'n' => 1], ['k' => 'pg', 'n' => 2]], ['pdfEnviado' => self::pdfDeDuasCores()]);

        $pdf = new PdfLido(self::montador()->montar($folha));

        self::assertSame(2, $pdf->paginas());
        [$r1, $g1, $b1] = PdfLido::media($pdf->imagem(1), 400, 600, 150, 150);
        [$r2, $g2, $b2] = PdfLido::media($pdf->imagem(2), 400, 600, 150, 150);
        self::assertGreaterThan(150, $r1 - max($g1, $b1), 'Página 1 do enviado (vermelha).');
        self::assertGreaterThan(150, $b2 - max($r2, $g2), 'Página 2 do enviado (azul).');
    }

    private static function montador(): MontadorDoPdf
    {
        return new MontadorDoPdf(GhostscriptDeTeste::binario());
    }

    private static function umaPaginaDepois(string $cor): PaginasDepoisDoConteudo
    {
        return new class ($cor) implements PaginasDepoisDoConteudo {
            public function __construct(private readonly string $cor)
            {
            }

            public function quantas(): int
            {
                return 1;
            }

            public function desenhar(int $indice, Tela $tela, RecursosDaFolha $recursos): void
            {
                $tela->retangulo(200, 300, 400, 400, Cor::hex($this->cor));
            }
        };
    }

    /** Um PDF de duas páginas lisas, vermelha e azul, escrito pelo próprio escritor (o Ghostscript o lê de volta). */
    private static function pdfDeDuasCores(): string
    {
        $escritor = new EscritorDePdf();
        $pdf = $escritor->inicio();
        foreach ([[200, 0, 0], [0, 0, 200]] as [$r, $g, $b]) {
            $imagem = imagecreatetruecolor(210, 297);
            imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, $r, $g, $b));
            $pdf .= $escritor->pagina($imagem);
            imagedestroy($imagem);
        }

        return $pdf . $escritor->fim('enviado');
    }

    /** Onde o código do rodapé deve terminar: cada letra do tamanho 20 crescendo 0,85, com 1,5 entre elas. */
    private static function fimDoCodigo(string $codigo): float
    {
        $x = self::INICIO_DO_CODIGO;
        foreach (mb_str_split($codigo) as $i => $letra) {
            $x += MedidorDeTexto::largura($letra, Fonte::MonoNegrito, 20 + $i * .85) + 1.5;
        }

        return $x - 1.5;
    }

    /** O pixel vermelho (#a3232b) mais à direita na linha do código, antes do selo. */
    private static function fimDoVermelho(\GdImage $imagem): float
    {
        $fim = 0;
        for ($py = (int) ((self::BASE_DO_CODIGO - 24) * Tela::ESCALA); $py <= (int) ((self::BASE_DO_CODIGO + 2) * Tela::ESCALA); ++$py) {
            for ($px = (int) (self::INICIO_DO_CODIGO * Tela::ESCALA); $px < (int) (760 * Tela::ESCALA); ++$px) {
                $cor = imagecolorat($imagem, $px, $py);
                if ((($cor >> 16) & 0xFF) > 120 && (($cor >> 8) & 0xFF) < 90 && ($cor & 0xFF) < 100) {
                    $fim = max($fim, $px);
                }
            }
        }
        imagedestroy($imagem);

        return $fim / Tela::ESCALA;
    }
}
