<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\CasoResumoOutput;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planilha da lista de cobranças da carteira ("Exportar lista (.xlsx)", desenho Carteira 1B).
 *
 * Mesmo padrão do `FolhaPontoXlsxExporter`: a planilha é montada dentro do callback e escrita direto
 * na saída da resposta, sem passar por arquivo em disco.
 *
 * As colunas são as da tabela da tela (`_resultado_casos.html.twig`), uma por informação que ela
 * mostra: identificação e descrição do objeto, pessoa cobrada, estado, "pronto para encerrar", saldo,
 * vencido e o grampo de documentos. Nada além: a tela não mostra CPF, e-mail nem telefone, e a
 * planilha também não. Valores em REAIS e como NÚMERO (com formato de moeda), para a planilha somar e
 * filtrar; o texto vai como texto explícito, para que identificação como "05-03" não vire data nem
 * conteúdo começado por "=" vire fórmula.
 */
final class ListaCarteiraXlsxExporter
{
    private const FORMATO_REAIS = '#,##0.00';

    /**
     * @param list<CasoResumoOutput> $casos
     */
    public function exportar(array $casos, string $rotuloObjeto, string $nomeArquivo): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($casos, $rotuloObjeto): void {
            $planilha = new Spreadsheet();
            $aba = $planilha->getActiveSheet();
            $aba->setTitle('Cobranças');

            $this->escreverCabecalho($aba, $rotuloObjeto);
            $this->escreverLinhas($aba, $casos);

            $writer = new Xlsx($planilha);
            $writer->save('php://output');
            $planilha->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nomeArquivo));
        // Lista de devedores: não fica em cache compartilhado nem no do navegador.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Cabeçalho da planilha, na ordem das colunas da tela. A primeira usa o rótulo do objeto da
     * carteira ("Unidade", "Contrato"...), como o cabeçalho da tabela.
     *
     * @return list<string>
     */
    public static function cabecalho(string $rotuloObjeto): array
    {
        return [
            $rotuloObjeto,
            'Descrição',
            'Pessoa cobrada',
            'Estado',
            'Pronto para encerrar',
            'Saldo (R$)',
            'Vencido (R$)',
            'Tem documentos',
        ];
    }

    private function escreverCabecalho(Worksheet $aba, string $rotuloObjeto): void
    {
        foreach (self::cabecalho($rotuloObjeto) as $i => $titulo) {
            $aba->setCellValueExplicit([$i + 1, 1], $titulo, DataType::TYPE_STRING);
        }

        $aba->getStyle('A1:H1')->getFont()->setBold(true);
        $aba->freezePane('A2');

        foreach (['A' => 18, 'B' => 32, 'C' => 36, 'D' => 15, 'E' => 12, 'F' => 15, 'G' => 15, 'H' => 12] as $coluna => $largura) {
            $aba->getColumnDimension($coluna)->setWidth($largura);
        }
    }

    /**
     * @param list<CasoResumoOutput> $casos
     */
    private function escreverLinhas(Worksheet $aba, array $casos): void
    {
        $linha = 2;
        foreach ($casos as $caso) {
            $aba->setCellValueExplicit([1, $linha], $caso->objetoIdentificacao, DataType::TYPE_STRING);
            $aba->setCellValueExplicit([2, $linha], $caso->objetoDescricao ?? '', DataType::TYPE_STRING);
            $aba->setCellValueExplicit([3, $linha], $caso->pessoaCobradaNome, DataType::TYPE_STRING);
            $aba->setCellValueExplicit([4, $linha], $caso->statusLabel, DataType::TYPE_STRING);
            $aba->setCellValueExplicit([5, $linha], $caso->prontoParaEncerrar ? 'Sim' : 'Não', DataType::TYPE_STRING);
            // Centavos (inteiro, como o sistema guarda) viram reais só aqui, na apresentação.
            $aba->setCellValueExplicit([6, $linha], $caso->saldoExigivel / 100, DataType::TYPE_NUMERIC);
            $aba->setCellValueExplicit([7, $linha], $caso->saldoVencido / 100, DataType::TYPE_NUMERIC);
            $aba->setCellValueExplicit([8, $linha], $caso->temDocumentos ? 'Sim' : 'Não', DataType::TYPE_STRING);
            ++$linha;
        }

        if ($linha > 2) {
            $aba->getStyle('F2:G' . ($linha - 1))->getNumberFormat()->setFormatCode(self::FORMATO_REAIS);
        }
    }
}
