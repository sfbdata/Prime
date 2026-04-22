<?php

namespace App\Service\Ponto;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FolhaPontoXlsxExporter
{
    public function exportar(array $folhaRows, string $nomeArquivo): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($folhaRows) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Folha Ponto');

            $sheet->fromArray(['Dia do Mês', 'Dia da Semana', 'Entrada', 'Repouso', 'Retorno', 'Saída'], null, 'A1');
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);

            $linha = 2;
            foreach ($folhaRows as $row) {
                $sheet->setCellValueExplicit("A{$linha}", $row['diaMes'], DataType::TYPE_STRING);
                $sheet->setCellValue("B{$linha}", $row['diaSemana']);
                $sheet->setCellValue("C{$linha}", $row['entrada']);
                $sheet->setCellValue("D{$linha}", $row['repouso']);
                $sheet->setCellValue("E{$linha}", $row['retorno']);
                $sheet->setCellValue("F{$linha}", $row['saida']);

                if ($row['fimSemana']) {
                    $sheet->getStyle("A{$linha}:F{$linha}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFEAEAEA');
                }

                $linha++;
            }

            foreach (range('A', 'F') as $coluna) {
                $sheet->getColumnDimension($coluna)->setAutoSize(true);
            }

            $sheet->freezePane('A2');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nomeArquivo));

        return $response;
    }
}
