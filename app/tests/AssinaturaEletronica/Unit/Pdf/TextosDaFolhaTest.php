<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\ModoDaFolha;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\Tela;
use App\AssinaturaEletronica\Pdf\TextosDaFolha;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os textos do PDF vêm do bloco `pdf:` do catálogo da tela (spec da etapa 3, seção 2, item 10): cada texto sai da
 * sua chave, o desenho lê os textos da folha e, com o catálogo de verdade, os textos são os do desenho (dc:409-412).
 *
 * O PDF é imagem (uma JPEG por página): o texto impresso não se lê de volta. A prova de que um texto fixo vem da
 * tradução é que trocar só a chave dele muda a página impressa; com o texto escrito no código, a página não mudaria.
 */
#[CoversClass(TextosDaFolha::class)]
#[CoversClass(MontadorDoPdf::class)]
final class TextosDaFolhaTest extends TestCase
{
    /** A segunda linha do hash no rodapé de página inteira: base em 1414 - 172 + 73, a partir de 92 + 116 + 24. */
    private const SEGUNDA_LINHA_DO_HASH = [232.0, 1306.0, 300.0, 12.0];

    private static ?PdfLido $referencia = null;

    #[TestDox('Com o catálogo de verdade, os textos são exatamente os do desenho')]
    public function testCatalogoTemOsTextosDoDesenho(): void
    {
        $textos = FolhaDeTeste::textos();
        $quando = new \DateTimeImmutable('2026-11-09 14:05:03');

        self::assertSame('RASCUNHO', $textos->rascunho);
        self::assertSame('PENDENTE', $textos->pendente);
        self::assertSame('Assinatura', $textos->assinatura);
        self::assertSame('Confira em bluejus.com.br/assinar/validar ou leia o QR Code.', $textos->confira);
        self::assertSame('BLUEJUS ASSINA', $textos->faixa);
        self::assertSame('BLUEJUS · ', $textos->anelInterno);
        self::assertSame('Blue', $textos->marcaAzul);
        self::assertSame('Jus', $textos->marcaClara);
        self::assertSame('BlueJus', $textos->produtor);
        self::assertSame('SHA-256 ', $textos->prefixoDoHash);
        self::assertSame('        ', $textos->recuoDoHash);
        self::assertSame('CÓDIGO DE VALIDAÇÃO · PÁGINA 2 DE 7', $textos->rotuloDoRodape(2, 7));
        self::assertSame('Emitido em 09/11/2026, 14:05:03. Vale somente com registro correspondente no BlueJus.', $textos->emitido($quando));
        self::assertSame('Assinado em 09/11/2026, 14:05:03', $textos->assinadoEm($quando));
        self::assertSame('BLUEJUS · BJA-1 · ', $textos->marcaDagua('BJA-1'));
        self::assertSame('BLUEJUS ASSINATURA ELETRONICA DOCUMENTO AUTENTICO BJA-1 ', $textos->microtexto('BJA-1'));
        self::assertSame('BLUEJUS AUTENTICO BJA-1 ', $textos->anel('BJA-1'));
        self::assertSame('Documento BlueJus BJA-1', $textos->titulo('BJA-1'));
    }

    #[TestDox('Página e total saem como no desenho, sem separador de milhar')]
    public function testRodapeSemSeparadorDeMilhar(): void
    {
        self::assertSame('CÓDIGO DE VALIDAÇÃO · PÁGINA 1000 DE 1200', FolhaDeTeste::textos()->rotuloDoRodape(1000, 1200));
    }

    #[TestDox('Cada texto sai da sua chave do bloco pdf:, com os valores nos marcadores')]
    public function testCadaTextoVemDaSuaChave(): void
    {
        $textos = new TextosDaFolha(new TradutorDeTeste());
        $quando = new \DateTimeImmutable('2026-11-09 14:05:03');

        self::assertSame('[rascunho]', $textos->rascunho);
        self::assertSame('[pendente]', $textos->pendente);
        self::assertSame('[assinatura]', $textos->assinatura);
        self::assertSame('[confira]', $textos->confira);
        self::assertSame('[faixa]', $textos->faixa);
        self::assertSame('[anel_interno]', $textos->anelInterno);
        self::assertSame('[marca_blue]', $textos->marcaAzul);
        self::assertSame('[marca_jus]', $textos->marcaClara);
        self::assertSame('[produtor]', $textos->produtor);
        self::assertSame('[sha256] ', $textos->prefixoDoHash);
        self::assertSame(str_repeat(' ', mb_strlen('[sha256] ')), $textos->recuoDoHash, 'O recuo acompanha o rótulo do hash.');
        self::assertSame('[rotulo_rodape pagina=2 total=7]', $textos->rotuloDoRodape(2, 7));
        self::assertSame('[emitido data=09/11/2026, 14:05:03]', $textos->emitido($quando));
        self::assertSame('[assinado_em data=09/11/2026, 14:05:03]', $textos->assinadoEm($quando));
        self::assertSame('[marca_dagua codigo=BJA-1]', $textos->marcaDagua('BJA-1'));
        self::assertSame('[microtexto codigo=BJA-1]', $textos->microtexto('BJA-1'));
        self::assertSame('[anel codigo=BJA-1]', $textos->anel('BJA-1'));
        self::assertSame('[titulo codigo=BJA-1]', $textos->titulo('BJA-1'));
    }

    #[TestDox('O PDF lê os textos da folha: rodapé, fundo, selo e assinatura pedem ao tradutor; título e produtor trocados aparecem no arquivo')]
    public function testPdfLeOsTextosDaFolha(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $tradutor = new TradutorDeTeste(FolhaDeTeste::tradutor(), [
            'titulo' => 'Título trocado {codigo}',
            'produtor' => 'Produtor trocado',
        ]);
        $folha = FolhaDeTeste::folha(mudancas: ['textos' => FolhaDeTeste::textos($tradutor)]);
        $tradutor->pedidos = [];

        $pdf = PdfLido::dosPedacos((new MontadorDoPdf(GhostscriptDeTeste::binario()))->emPedacos($folha));

        self::assertSame('Título trocado ' . FolhaDeTeste::CODIGO, $pdf->titulo());
        self::assertSame('Produtor trocado', $pdf->produtor());
        $codigo = ['codigo' => FolhaDeTeste::CODIGO];
        $data = ['data' => '09/11/2026, 14:05:03'];
        $esperados = [
            'anel' => $codigo,
            'assinado_em' => $data,
            'emitido' => $data,
            'marca_dagua' => $codigo,
            'microtexto' => $codigo,
            'rotulo_rodape' => ['pagina' => '1', 'total' => '1'],
            'titulo' => $codigo,
        ];
        $pedidos = $tradutor->pedidos;
        ksort($pedidos);
        self::assertSame($esperados, $pedidos, 'Na montagem, só os textos com marcador vão ao tradutor; os fixos já vêm da folha.');
    }

    /** @return iterable<string, array{string, string}> chave fixa do bloco `pdf:` e o marcador que a substitui */
    public static function textosFixosImpressos(): iterable
    {
        yield 'PENDENTE no selo' => ['pendente', 'X-PEND'];
        yield 'anel de dentro do selo' => ['anel_interno', 'X-ANEL · '];
        yield 'instrução do rodapé' => ['confira', 'X-CONFIRA em outro lugar.'];
        yield 'rótulo do hash' => ['sha256', 'X-SHA-256-X'];
        yield 'faixa lateral do fundo' => ['faixa', 'X-FAIXA LATERAL'];
        yield 'marca azul do cabeçalho' => ['marca_blue', 'X-Azul'];
        yield 'marca clara do cabeçalho' => ['marca_jus', 'X-Clara'];
        yield 'lugar de assinatura sem signatário' => ['assinatura', 'X-Assinatura'];
    }

    #[DataProvider('textosFixosImpressos')]
    #[TestDox('Trocar só a chave $chave muda a página impressa: o texto fixo vem da tradução')]
    public function testTextoFixoImpressoVemDaChave(string $chave, string $marcador): void
    {
        FolhaDeTeste::exigirFontes($this);

        $trocada = self::paginaImpressa([$chave => $marcador]);

        self::assertNotSame(self::referencia()->jpegs()[0], $trocada->jpegs()[0], 'A página impressa não usa a chave ' . $chave . '.');
    }

    #[TestDox('O recuo da segunda linha do hash acompanha o rótulo da chave sha256')]
    public function testRecuoDoHashVemDoRotulo(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $referencia = self::referencia()->imagem(1);
        $trocada = self::paginaImpressa(['sha256' => 'X-SHA-256-X'])->imagem(1);

        try {
            self::assertFalse(self::mesmaRegiao($referencia, $trocada, ...self::SEGUNDA_LINHA_DO_HASH), 'Com o rótulo mais longo, a segunda linha recua mais.');
        } finally {
            imagedestroy($referencia);
            imagedestroy($trocada);
        }
    }

    #[TestDox('RASCUNHO e o produtor vêm das traduções: aparecem no /Title e no /Producer do PDF')]
    public function testRascunhoEProdutorNoArquivo(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $tradutor = new TradutorDeTeste(FolhaDeTeste::tradutor(), ['rascunho' => 'X-RASCUNHO', 'produtor' => 'X-Produtor']);
        $folha = FolhaDeTeste::folha(mudancas: ['modo' => ModoDaFolha::Rascunho, 'codigo' => null, 'textos' => FolhaDeTeste::textos($tradutor)]);

        $pdf = PdfLido::dosPedacos((new MontadorDoPdf(GhostscriptDeTeste::binario()))->emPedacos($folha));

        self::assertSame('Documento BlueJus X-RASCUNHO', $pdf->titulo());
        self::assertSame('X-Produtor', $pdf->produtor());
    }

    /** A página do catálogo de verdade, montada uma vez para todas as comparações. */
    private static function referencia(): PdfLido
    {
        return self::$referencia ??= self::paginaImpressa([]);
    }

    /**
     * A folha padrão com um lugar de assinatura sem signatário (imprime "Assinatura"), sem logo (marca em texto),
     * pendente (selo com "PENDENTE") e as chaves de `$trocas` trocadas.
     *
     * @param array<string, string> $trocas
     */
    private static function paginaImpressa(array $trocas): PdfLido
    {
        $blocos = [...FolhaDeTeste::blocosDeUmaPagina(), ['k' => 'a', 'i' => 9, 'r' => '']];
        $textos = FolhaDeTeste::textos(new TradutorDeTeste(FolhaDeTeste::tradutor(), $trocas));
        $folha = FolhaDeTeste::folha($blocos, ['textos' => $textos]);

        return PdfLido::dosPedacos((new MontadorDoPdf(GhostscriptDeTeste::binario()))->emPedacos($folha));
    }

    /** Os pixels de um retângulo (em unidades do desenho) são os mesmos nas duas imagens. */
    private static function mesmaRegiao(\GdImage $a, \GdImage $b, float $x, float $y, float $largura, float $altura): bool
    {
        for ($py = (int) ($y * Tela::ESCALA); $py < (int) (($y + $altura) * Tela::ESCALA); ++$py) {
            for ($px = (int) ($x * Tela::ESCALA); $px < (int) (($x + $largura) * Tela::ESCALA); ++$px) {
                if (imagecolorat($a, $px, $py) !== imagecolorat($b, $px, $py)) {
                    return false;
                }
            }
        }

        return true;
    }
}
