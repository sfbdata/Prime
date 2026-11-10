<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\CartaoDoManifesto;
use App\AssinaturaEletronica\Pdf\ManifestoDeAssinaturas;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\RasterDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\RecursosDaFolha;
use App\AssinaturaEletronica\Pdf\SemPaginasDepoisDoConteudo;
use App\AssinaturaEletronica\Pdf\SituacaoNoManifesto;
use App\AssinaturaEletronica\Pdf\Tela;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O manifesto de assinaturas (A2, dc:417-420): quantas páginas ele pede antes de desenhar (cartões de 128 em 128 a
 * partir de 228 até a base 1202; trilha 6 abaixo, eventos de 18 em 18, título da trilha sem ficar sozinho), as cores
 * do desenho e a numeração do PDF montado.
 */
#[CoversClass(ManifestoDeAssinaturas::class)]
#[CoversClass(SituacaoNoManifesto::class)]
final class ManifestoDeAssinaturasTest extends TestCase
{
    #[TestDox('dois signatários e cinco eventos cabem numa página')]
    public function testUmaPagina(): void
    {
        self::assertSame(1, self::manifesto(2, 5)->quantas());
    }

    #[TestDox('cartões: sete cabem na primeira página (o oitavo passaria de 1202) e o oitavo continua na seguinte')]
    public function testCartoesContinuam(): void
    {
        self::assertSame(1, self::manifesto(7, 0)->quantas(), '7 cartões e o título da trilha sem eventos');
        self::assertSame(2, self::manifesto(8, 3)->quantas());
    }

    #[TestDox('trilha inteira (E3-9): com um cartão, 46 eventos cabem; o 47º e os seguintes continuam, 58 por página')]
    public function testTrilhaContinua(): void
    {
        self::assertSame(1, self::manifesto(1, 46)->quantas());
        self::assertSame(2, self::manifesto(1, 47)->quantas());
        self::assertSame(2, self::manifesto(1, 46 + 58)->quantas());
        self::assertSame(3, self::manifesto(1, 46 + 58 + 1)->quantas());
    }

    #[TestDox('depois dos cartões da página 2, a trilha começa 6 abaixo e os eventos seguem de 18 em 18')]
    public function testTrilhaDepoisDosCartoesDaContinuacao(): void
    {
        // 8 cartões: 7 na página 1, o 8º no alto da página 2 (170); título da trilha em 304, eventos a partir de 324:
        // 49 eventos cabem nessa página.
        self::assertSame(2, self::manifesto(8, 49)->quantas());
        self::assertSame(3, self::manifesto(8, 50)->quantas());
    }

    #[TestDox('o título da trilha não fica sozinho no pé: desce com o primeiro evento para a página seguinte')]
    public function testTituloDaTrilhaNaoFicaSozinho(): void
    {
        // 15 cartões: 7 na página 1 e 8 na página 2 (o último em 1066); o título da trilha iria a 1200. Sem eventos,
        // ele cabe ali. Com eventos, desce para 170 da página 3 e os eventos começam em 190: 57 cabem (em 170 seriam 58).
        self::assertSame(2, self::manifesto(15, 0)->quantas());
        self::assertSame(3, self::manifesto(15, 57)->quantas());
        self::assertSame(4, self::manifesto(15, 58)->quantas());
    }

    #[TestDox('cores do desenho: assinado #1e7b45, recusado #a3232b, pendente #7a5200')]
    public function testCores(): void
    {
        self::assertSame([0x1e, 0x7b, 0x45], self::rgb(SituacaoNoManifesto::Assinado));
        self::assertSame([0xa3, 0x23, 0x2b], self::rgb(SituacaoNoManifesto::Recusado));
        self::assertSame([0x7a, 0x52, 0x00], self::rgb(SituacaoNoManifesto::Pendente));
    }

    #[TestDox('desenho: a barra de 4 do cartão na cor da situação em 228; na página de continuação, o cartão em 170')]
    public function testDesenhoDosCartoes(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $manifesto = self::manifesto(8, 0);
        $recursos = new RecursosDaFolha(FolhaDeTeste::folha(null, ['paginasDepois' => new SemPaginasDepoisDoConteudo()]), new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), null));

        $primeira = Tela::pagina();
        $manifesto->desenhar(0, $primeira, $recursos);
        $segunda = Tela::pagina();
        $manifesto->desenhar(1, $segunda, $recursos);

        $verde = PdfLido::media($primeira->gd(), 92.5, 240, 3, 90);
        self::assertEqualsWithDelta([0x1e, 0x7b, 0x45], $verde, 8, 'barra verde do 1º cartão (assinado)');
        self::assertLessThan(120, PdfLido::maisEscuro($primeira->gd(), 92, 150, 400, 25), 'o título em 170');
        $vinho = PdfLido::media($segunda->gd(), 92.5, 180, 3, 90);
        self::assertEqualsWithDelta([0xa3, 0x23, 0x2b], $vinho, 8, 'o 8º cartão (recusado) no alto da página 2');
        self::assertGreaterThan(240, PdfLido::maisEscuro($segunda->gd(), 92, 140, 400, 25), 'sem repetir o título');
        self::assertLessThan(200, PdfLido::maisEscuro($segunda->gd(), 92, 1205, 600, 16), 'a nota legal em 1218');

        $primeira->liberar();
        $segunda->liberar();
        $recursos->liberar();
    }

    #[TestDox('no PDF montado, o manifesto entra depois do conteúdo e na contagem de páginas (PÁGINA n DE total)')]
    public function testPaginasNoPdf(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $manifesto = self::manifesto(2, 47);
        $pdf = '';
        foreach ((new MontadorDoPdf(ghostscriptBin: GhostscriptDeTeste::binario()))->emPedacos(FolhaDeTeste::folha(null, ['paginasDepois' => $manifesto])) as $pedaco) {
            $pdf .= $pedaco;
        }

        self::assertSame(2, $manifesto->quantas());
        self::assertSame(1 + 2, (new PdfLido($pdf))->paginas());

        $semManifesto = '';
        foreach ((new MontadorDoPdf(ghostscriptBin: GhostscriptDeTeste::binario()))->emPedacos(FolhaDeTeste::folha(null, ['paginasDepois' => new SemPaginasDepoisDoConteudo()])) as $pedaco) {
            $semManifesto .= $pedaco;
        }
        self::assertSame(1, (new PdfLido($semManifesto))->paginas());
    }

    /** Signatários alternando assinado, pendente e recusado (o 8º, índice 7, é recusado), e eventos numerados. */
    private static function manifesto(int $signatarios, int $eventos): ManifestoDeAssinaturas
    {
        $situacoes = [SituacaoNoManifesto::Assinado, SituacaoNoManifesto::Pendente, SituacaoNoManifesto::Recusado];
        $cartoes = [];
        for ($i = 0; $i < $signatarios; ++$i) {
            $situacao = $i === 7 ? SituacaoNoManifesto::Recusado : $situacoes[$i % 2];
            $cartoes[] = new CartaoDoManifesto($i, 'Signatário ' . $i, 'Parte · CPF ***.222.333-** · an***@exemplo.com', 'Tipo: Eletrônica simples', $situacao, 'Assinatura pendente', 'IP e dispositivo registrados na trilha do servidor.');
        }

        return new ManifestoDeAssinaturas(
            'MANIFESTO DE ASSINATURAS',
            'Procuração',
            $cartoes,
            'TRILHA DE EVENTOS',
            array_map(static fn (int $n): string => '09/11/2026, 10:00:00  Evento ' . $n, $eventos > 0 ? range(1, $eventos) : []),
            'Classificação das assinaturas conforme a Lei nº 14.063/2020. Este manifesto é parte integrante do documento.',
        );
    }

    /** @return array{int, int, int} */
    private static function rgb(SituacaoNoManifesto $situacao): array
    {
        $cor = $situacao->cor();

        return [$cor->r, $cor->g, $cor->b];
    }
}
