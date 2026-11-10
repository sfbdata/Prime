<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\TextosDaFolha;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os textos do PDF vêm do bloco `pdf:` do catálogo da tela (spec da etapa 3, seção 2, item 10): cada texto sai da
 * sua chave, o desenho lê os textos da folha e, com o catálogo de verdade, os textos são os do desenho (dc:409-412).
 */
#[CoversClass(TextosDaFolha::class)]
#[CoversClass(MontadorDoPdf::class)]
final class TextosDaFolhaTest extends TestCase
{
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
}
