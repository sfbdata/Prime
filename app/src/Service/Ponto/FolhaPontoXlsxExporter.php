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

            $sheet->fromArray(['Dia do Mês', 'Dia da Semana', 'Entrada', 'Repouso', 'Retorno', 'Saída', 'Justificativa'], null, 'A1');
            $sheet->getStyle('A1:G1')->getFont()->setBold(true);

            $linha = 2;
            foreach ($folhaRows as $row) {
                $sheet->setCellValueExplicit("A{$linha}", $row['diaMes'], DataType::TYPE_STRING);
                $sheet->setCellValue("B{$linha}", $row['diaSemana']);
                $sheet->setCellValue("C{$linha}", $row['entrada']);
                $sheet->setCellValue("D{$linha}", $row['repouso']);
                $sheet->setCellValue("E{$linha}", $row['retorno']);
                $sheet->setCellValue("F{$linha}", $row['saida']);

                $justificativaLabel = '';
                $j = $row['justificativa'] ?? null;
                if ($j !== null && $j->getStatus() === 'abonado') {
                    $justificativaLabel = $j->getLabelTipo() ?? '';
                    if ($j->isAbonoParcial() && $j->getHoraInicioAbono() && $j->getHoraFimAbono()) {
                        $justificativaLabel .= sprintf(
                            ' (Parcial: %s–%s)',
                            $j->getHoraInicioAbono()->format('H:i'),
                            $j->getHoraFimAbono()->format('H:i')
                        );
                    }
                }
                $sheet->setCellValue("G{$linha}", $justificativaLabel);

                if ($row['fimSemana']) {
                    $sheet->getStyle("A{$linha}:G{$linha}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFEAEAEA');
                } elseif (!empty($row['justificadoDia'])) {
                    $sheet->getStyle("A{$linha}:G{$linha}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFF3CD');
                } elseif (!empty($row['isFeriado'])) {
                    $sheet->getStyle("A{$linha}:G{$linha}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFF3CD');
                }

                $linha++;
            }

            foreach (range('A', 'G') as $coluna) {
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
