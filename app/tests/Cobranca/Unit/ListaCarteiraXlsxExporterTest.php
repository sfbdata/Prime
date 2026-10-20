<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Service\ListaCarteiraXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListaCarteiraXlsxExporter::class)]
final class ListaCarteiraXlsxExporterTest extends TestCase
{
    private function caso(
        string $identificacao,
        int $saldo,
        int $vencido,
        bool $pronto = false,
        bool $docs = false,
        ?string $descricao = null,
        string $pessoa = 'Fulana',
    ): CasoResumoOutput {
        return new CasoResumoOutput(
            id: 1,
            objetoId: 1,
            objetoIdentificacao: $identificacao,
            objetoDescricao: $descricao,
            carteiraId: 1,
            carteiraNome: 'Carteira',
            pessoaCobradaNome: $pessoa,
            statusLabel: 'Ativo',
            statusBadgeClass: 'bg-primary',
            prontoParaEncerrar: $pronto,
            saldoExigivel: $saldo,
            saldoVencido: $vencido,
            temVencido: $vencido > 0,
            atualizadoEm: null,
            temDocumentos: $docs,
        );
    }

    /**
     * @param list<CasoResumoOutput> $casos
     */
    private function gerar(array $casos, string $rotulo = 'Objeto'): Worksheet
    {
        $resposta = (new ListaCarteiraXlsxExporter())->exportar($casos, $rotulo, 'carteira-1-2026-10-20.xlsx');

        ob_start();
        $resposta->sendContent();
        $binario = (string) ob_get_clean();

        $arquivo = tempnam(sys_get_temp_dir(), 'lista_carteira_');
        self::assertIsString($arquivo);
        file_put_contents($arquivo, $binario);

        try {
            return IOFactory::load($arquivo)->getActiveSheet();
        } finally {
            unlink($arquivo);
        }
    }

    #[TestDox('Cabeçalho na ordem da tela, com o rótulo do objeto da carteira na primeira coluna')]
    public function testCabecalho(): void
    {
        $aba = $this->gerar([], 'Contrato');

        self::assertSame(
            [['Contrato', 'Descrição', 'Pessoa cobrada', 'Estado', 'Pronto para encerrar', 'Saldo (R$)', 'Vencido (R$)', 'Tem documentos']],
            $aba->toArray(null, false, false, false),
        );
    }

    #[TestDox('Centavos viram reais em célula NUMÉRICA; indicadores viram Sim/Não')]
    public function testValoresEmNumero(): void
    {
        $aba = $this->gerar([$this->caso('A-1', 123456, 0, pronto: false, docs: true), $this->caso('A-2', 0, 0, pronto: true)]);

        self::assertSame(DataType::TYPE_NUMERIC, $aba->getCell('F2')->getDataType());
        self::assertEqualsWithDelta(1234.56, $aba->getCell('F2')->getValue(), 0.0001);
        self::assertEqualsWithDelta(0.0, $aba->getCell('G2')->getValue(), 0.0001);
        self::assertSame('#,##0.00', $aba->getStyle('F2')->getNumberFormat()->getFormatCode());
        self::assertSame('Não', $aba->getCell('E2')->getValue());
        self::assertSame('Sim', $aba->getCell('H2')->getValue());
        self::assertSame('Sim', $aba->getCell('E3')->getValue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inicioDeFormula(): iterable
    {
        yield 'igual' => ['=HYPERLINK("http://x")'];
        yield 'mais' => ['+1+1'];
        yield 'menos' => ['-2+3'];
        yield 'arroba' => ['@SUM(1,1)'];
    }

    #[DataProvider('inicioDeFormula')]
    #[TestDox('Texto começado por $_dataName não vira fórmula na identificação, na descrição nem na pessoa')]
    public function testTextoComInicioDeFormulaFicaTexto(string $conteudo): void
    {
        $aba = $this->gerar([$this->caso($conteudo, 0, 0, descricao: $conteudo, pessoa: $conteudo)]);

        foreach (['A2', 'B2', 'C2'] as $celula) {
            self::assertSame(DataType::TYPE_STRING, $aba->getCell($celula)->getDataType(), $celula);
            self::assertSame($conteudo, $aba->getCell($celula)->getValue(), $celula);
        }
    }

    #[TestDox('Identificação como "05-03" não vira data')]
    public function testIdentificacaoNaoViraData(): void
    {
        $aba = $this->gerar([$this->caso('05-03', 0, 0)]);

        self::assertSame(DataType::TYPE_STRING, $aba->getCell('A2')->getDataType());
        self::assertSame('05-03', $aba->getCell('A2')->getValue());
    }

    #[TestDox('Resposta de download: tipo .xlsx, anexo com o nome pedido e sem cache')]
    public function testCabecalhosDaResposta(): void
    {
        $resposta = (new ListaCarteiraXlsxExporter())->exportar([], 'Objeto', 'carteira-7-2026-10-20.xlsx');

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $resposta->headers->get('Content-Type'));
        self::assertSame('attachment; filename=carteira-7-2026-10-20.xlsx', $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }
}
