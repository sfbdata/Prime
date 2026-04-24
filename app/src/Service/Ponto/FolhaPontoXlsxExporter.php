<?php

namespace App\Service\Ponto;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FolhaPontoXlsxExporter
{
    private const COLS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    public function exportar(array $folhaRows, string $nomeArquivo, array $cabecalho = []): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($folhaRows, $cabecalho) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Folha Ponto');

            $this->configurarLarguras($sheet);
            $linhaTabela = $this->escreverCabecalho($sheet, $cabecalho);
            $this->escreverTabela($sheet, $folhaRows, $cabecalho, $linhaTabela);
            $this->escreverRodape($sheet, $linhaTabela + count($folhaRows) + 2);

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $nomeArquivo));

        return $response;
    }

    private function configurarLarguras(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(6);   // Dia Mês
        $sheet->getColumnDimension('B')->setWidth(13);  // Dia da Semana
        $sheet->getColumnDimension('C')->setWidth(11);  // Entrada
        $sheet->getColumnDimension('D')->setWidth(11);  // Repouso
        $sheet->getColumnDimension('E')->setWidth(11);  // Retorno
        $sheet->getColumnDimension('F')->setWidth(11);  // Saída
        $sheet->getColumnDimension('G')->setWidth(38);  // Justificativa
        $sheet->getColumnDimension('H')->setWidth(2);   // espaço
        $sheet->getColumnDimension('I')->setWidth(28);  // bloco CNPJ/dir
    }

    /** Escreve o cabeçalho e retorna o número da linha onde começa o cabeçalho da tabela. */
    private function escreverCabecalho(Worksheet $sheet, array $c): int
    {
        $nomeEmpresa = $c['nomeEmpresa'] ?? '';
        $cnpj        = $c['cnpj'] ?? '';
        $inicioMes   = $c['inicioMes'] ?? '';
        $fimMes      = $c['fimMes'] ?? '';

        // Linha 1 — nome da empresa (esq)
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', $nomeEmpresa);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setUnderline(true)->setSize(10)->setName('Arial');
        $sheet->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(14);

        // Linha 2 — título LISTA DE FREQUÊNCIA (esq)
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'LISTA DE FREQUÊNCIA DE ' . $inicioMes . ' A ' . $fimMes);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setUnderline(true)->setSize(10)->setName('Arial');
        $sheet->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(14);

        // Bloco direito (I1:I8) — CNPJ, nome empresa, endereço
        $textoDir  = 'CNPJ : ' . $cnpj . "\n";
        $textoDir .= $nomeEmpresa . "\n";
        $textoDir .= ($c['enderecoLinha1'] ?? '');
        if (!empty($c['enderecoLinha2'])) {
            $textoDir .= "\n" . $c['enderecoLinha2'];
        }
        $sheet->mergeCells('I1:I8');
        $sheet->setCellValue('I1', $textoDir);
        $style = $sheet->getStyle('I1:I8');
        $style->getFont()->setSize(9)->setName('Arial');
        $style->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $style->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);

        // Linhas 3-8 — dados do trabalhador
        $campos = [
            3 => ['Trabalhador',    ($c['numeroTrabalhador'] ?? '') . ' ' . ($c['nomeUsuario'] ?? '')],
            4 => ['Cargo',          $c['cargo'] ?? ''],
            5 => ['CTPS',           $c['ctps'] ?? ''],
            6 => ['Série',          $c['serie'] ?? ''],
            7 => ['Descr. Jornada', $c['descrJornada'] ?? ''],
            8 => ['Lotação',        $c['lotacao'] ?? ''],
        ];

        foreach ($campos as $linha => [$label, $valor]) {
            $sheet->mergeCells("B{$linha}:G{$linha}");
            $sheet->setCellValue("A{$linha}", $label);
            $sheet->getStyle("A{$linha}")->getFont()->setBold(true)->setSize(10)->setName('Arial');
            $sheet->getStyle("A{$linha}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->setCellValue("B{$linha}", ': ' . $valor);
            $sheet->getStyle("B{$linha}")->getFont()->setSize(10)->setName('Arial');
            $sheet->getStyle("B{$linha}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($linha)->setRowHeight(14);
        }

        // Linha 9 — espaço
        $sheet->getRowDimension(9)->setRowHeight(4);

        // Linha 10 — cabeçalho da tabela
        $this->escreverLinhaHeader($sheet, 10, $c);

        return 10;
    }

    private function escreverLinhaHeader(Worksheet $sheet, int $linha, array $c): void
    {
        $horaEntrada = $c['horaEntrada'] ?? '08:00 h';
        $horaRepouso = $c['horaRepouso'] ?? '12:00 h';
        $horaRetorno = $c['horaRetorno'] ?? '13:00 h';
        $horaSaida   = $c['horaSaida']   ?? '17:00 h';

        $titulos = [
            'A' => "Dia\nMês",
            'B' => "Dia da\nSemana",
            'C' => $horaEntrada . "\nEntrada",
            'D' => $horaRepouso . "\nRepouso",
            'E' => $horaRetorno . "\nRetorno",
            'F' => $horaSaida . "\nSaída",
            'G' => 'Justificativa',
        ];

        foreach ($titulos as $col => $titulo) {
            $cell = "{$col}{$linha}";
            $sheet->setCellValue($cell, $titulo);
            $s = $sheet->getStyle($cell);
            $s->getFont()->setBold(true)->setSize(10)->setName('Arial');
            $aln = $s->getAlignment();
            $aln->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $aln->setHorizontal($col === 'G' ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER);
            $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        $sheet->getRowDimension($linha)->setRowHeight(26);
    }

    private function escreverTabela(Worksheet $sheet, array $folhaRows, array $cabecalho, int $linhaHeader): void
    {
        $linha = $linhaHeader + 1;

        foreach ($folhaRows as $row) {
            $sheet->setCellValueExplicit("A{$linha}", sprintf('%02d', (int) $row['diaMes']), DataType::TYPE_STRING);
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

            $s = $sheet->getStyle("A{$linha}:G{$linha}");
            $s->getFont()->setSize(10)->setName('Arial');
            $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            // Alinhamento central para colunas de hora/dia
            $sheet->getStyle("A{$linha}:F{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$linha}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Fundo cinza para fins de semana e feriados (igual ao PDF)
            if (!empty($row['fimSemana']) || !empty($row['isFeriado'])) {
                $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8E8E8');
            }

            $sheet->getRowDimension($linha)->setRowHeight(14);
            $linha++;
        }

        // Linha em branco extra (como no PDF)
        $sheet->getStyle("A{$linha}:G{$linha}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($linha)->setRowHeight(14);

        $sheet->freezePane('A' . ($linhaHeader + 1));
    }

    private function escreverRodape(Worksheet $sheet, int $linha): void
    {
        $sheet->mergeCells("A{$linha}:G{$linha}");
        $sheet->setCellValue("A{$linha}", 'Conforme a Portaria nº 3.626, Artigo 13 de 13/11/91, esta folha substitui o Quadro de Horário de Trabalho, inclusive o de menores.');
        $sheet->getStyle("A{$linha}")->getFont()->setSize(8)->setItalic(true)->setName('Arial');
        $sheet->getRowDimension($linha)->setRowHeight(12);
    }
}
