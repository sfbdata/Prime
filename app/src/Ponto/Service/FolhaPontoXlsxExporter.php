<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FolhaPontoXlsxExporter
{
    private const COR_TITULO      = 'FF1A3A5C';
    private const COR_SUBTITULO   = 'FFE8F0FB';
    private const COR_SECAO       = 'FFD0DFF0';
    private const COR_CINZA       = 'FFE8E8E8';
    private const COR_FERIADO     = 'FFCC0000';
    private const COR_CONFORME    = 'FF2E7D32';

    public function exportar(array $folhaRows, string $nomeArquivo, array $c = []): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($folhaRows, $c) {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Folha Ponto');

            $this->configurarLarguras($sheet);
            $linha = $this->escreverTitulo($sheet, $c);
            $linha = $this->escreverCabecalhoEmpresa($sheet, $c, $linha);
            $linha = $this->escreverDadosEmpregado($sheet, $c, $linha);
            $linha = $this->escreverHeaderTabela($sheet, $linha);
            $linha = $this->escreverLinhasDados($sheet, $folhaRows, $linha);
            $linha = $this->escreverBlocoInferior($sheet, $c, $linha);
            $linha = $this->escreverAssinaturas($sheet, $c, $linha);
            $this->escreverRodape($sheet, $linha);

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
        $sheet->getColumnDimension('A')->setWidth(5);    // Dia
        $sheet->getColumnDimension('B')->setWidth(14);   // Dia da Semana
        $sheet->getColumnDimension('C')->setWidth(11);   // Entrada
        $sheet->getColumnDimension('D')->setWidth(11);   // Início Intervalo
        $sheet->getColumnDimension('E')->setWidth(11);   // Fim Intervalo
        $sheet->getColumnDimension('F')->setWidth(11);   // Saída
        $sheet->getColumnDimension('G')->setWidth(14);   // Horas Trabalhadas
        $sheet->getColumnDimension('H')->setWidth(12);   // Horas Extras
        $sheet->getColumnDimension('I')->setWidth(14);   // Banco de Horas
        $sheet->getColumnDimension('J')->setWidth(42);   // Justificativa
    }

    /** Escreve título e período; retorna próxima linha livre. */
    private function escreverTitulo(Worksheet $sheet, array $c): int
    {
        $mes = $c['mes'] ?? '';
        $ano = $c['ano'] ?? '';
        $titulo = sprintf('FOLHA DE PONTO – %02d/%d', (int)$mes, (int)$ano);

        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', $titulo);
        $s = $sheet->getStyle('A1');
        $s->getFont()->setBold(true)->setSize(13)->setName('Arial')->getColor()->setARGB('FFFFFFFF');
        $s->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_TITULO);
        $sheet->getRowDimension(1)->setRowHeight(20);

        $sheet->mergeCells('A2:J2');
        $sheet->setCellValue('A2', 'Período: ' . ($c['inicioMes'] ?? '') . ' a ' . ($c['fimMes'] ?? ''));
        $s2 = $sheet->getStyle('A2');
        $s2->getFont()->setSize(9)->setName('Arial');
        $s2->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $s2->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_SUBTITULO);
        $s2->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension(2)->setRowHeight(14);

        return 3;
    }

    /** Escreve bloco empresa (esq A:E) + regime de jornada (dir F:J); retorna próxima linha. */
    private function escreverCabecalhoEmpresa(Worksheet $sheet, array $c, int $inicio): int
    {
        $nomeEmpresa = $c['nomeEmpresa'] ?? '';
        $cnpj        = $c['cnpj'] ?? '';
        $end1        = $c['enderecoLinha1'] ?? '';
        $end2        = $c['enderecoLinha2'] ?? '';

        // Empresa — linhas $inicio até $inicio+3
        $dadosEmpresa = [
            [$nomeEmpresa, true, false, true],   // [texto, bold, italic, underline]
            ['CNPJ: ' . $cnpj, false, false, false],
            [$end1, false, false, false],
            [$end2, false, false, false],
        ];

        $lin = $inicio;
        foreach ($dadosEmpresa as [$texto, $bold, $italic, $underline]) {
            $sheet->mergeCells("A{$lin}:E{$lin}");
            $sheet->setCellValue("A{$lin}", $texto);
            $font = $sheet->getStyle("A{$lin}")->getFont()->setSize(9)->setName('Arial');
            $font->setBold($bold)->setItalic($italic)->setUnderline($underline);
            $sheet->getStyle("A{$lin}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($lin)->setRowHeight(14);
            $lin++;
        }

        // Regime de Jornada — box F:{inicio}:J:{inicio+3}
        $linFim = $inicio + 3;

        // Título do box
        $sheet->mergeCells("F{$inicio}:J{$inicio}");
        $sheet->setCellValue("F{$inicio}", 'REGIME DE JORNADA');
        $sT = $sheet->getStyle("F{$inicio}");
        $sT->getFont()->setBold(true)->setSize(9)->setName('Arial');
        $sT->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sT->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_SECAO);
        $sT->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sT->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
        $sT->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
        $sT->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        // Conteúdo do box (linhas $inicio+1 a $linFim)
        $linhasRegime = [
            'Jornada: '              . ($c['jornadaSemanalTexto'] ?? ''),
            'Distribuição: '         . ($c['distribuicaoJornada'] ?? ''),
            'Banco de Horas: Sim (acordo individual)',
            'Intervalo Intrajornada: ' . ($c['minimoRepousoTexto'] ?? '') . ' (mínimo)',
            'Intervalo Interjornada: mínimo ' . ($c['minimoInterjornadaTexto'] ?? ''),
        ];

        $linR = $inicio + 1;
        foreach ($linhasRegime as $textoRegime) {
            $sheet->mergeCells("F{$linR}:J{$linR}");
            $sheet->setCellValue("F{$linR}", $textoRegime);
            $sR = $sheet->getStyle("F{$linR}");
            $sR->getFont()->setSize(8)->setName('Arial');
            $sR->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $sR->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
            $sR->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getRowDimension($linR)->setRowHeight(13);
            $linR++;
        }

        // Borda inferior do box
        $linFimBox = $inicio + count($linhasRegime);
        $sheet->getStyle("F{$linFimBox}:J{$linFimBox}")
            ->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        return $inicio + 4;
    }

    /** Escreve os 4 blocos de dados do empregado; retorna próxima linha. */
    private function escreverDadosEmpregado(Worksheet $sheet, array $c, int $inicio): int
    {
        // Título da seção
        $sheet->mergeCells("A{$inicio}:J{$inicio}");
        $sheet->setCellValue("A{$inicio}", 'DADOS DO EMPREGADO');
        $sT = $sheet->getStyle("A{$inicio}");
        $sT->getFont()->setBold(true)->setSize(9)->setName('Arial');
        $sT->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sT->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_SECAO);
        $sT->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($inicio)->setRowHeight(14);

        $lin = $inicio + 1;

        // Linha 1: Código | Nome | CPF
        $this->escreverCelulaEmpregado($sheet, "A{$lin}", "C{$lin}", 'Código: ' . ($c['codigoFuncionario'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "D{$lin}", "G{$lin}", 'Nome: ' . ($c['nomeUsuario'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "H{$lin}", "J{$lin}", 'CPF: ' . ($c['cpf'] ?? ''));
        $sheet->getRowDimension($lin)->setRowHeight(13);
        $lin++;

        // Linha 2: Cargo | CTPS | Série | Lotação
        $this->escreverCelulaEmpregado($sheet, "A{$lin}", "C{$lin}", 'Cargo: ' . ($c['cargo'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "D{$lin}", "F{$lin}", 'CTPS: ' . ($c['ctps'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "G{$lin}", "H{$lin}", 'Série: ' . ($c['serie'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "I{$lin}", "J{$lin}", 'Lotação: ' . ($c['lotacao'] ?? ''));
        $sheet->getRowDimension($lin)->setRowHeight(13);
        $lin++;

        // Linha 3: Jornada Semanal | Horário Contratual | Intervalo | Tipo de Ponto
        $this->escreverCelulaEmpregado($sheet, "A{$lin}", "B{$lin}", 'Jornada Semanal: ' . ($c['jornadaSemanal'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "C{$lin}", "E{$lin}", 'Horário Contratual: ' . ($c['horarioContratual'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "F{$lin}", "H{$lin}", 'Intervalo: ' . ($c['intervaloTexto'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "I{$lin}", "J{$lin}", 'Tipo de Ponto: Eletrônico');
        $sheet->getRowDimension($lin)->setRowHeight(13);
        $lin++;

        // Linha 4: Escala | Data de admissão
        $this->escreverCelulaEmpregado($sheet, "A{$lin}", "F{$lin}", 'Escala: ' . ($c['escalaDescricao'] ?? ''));
        $this->escreverCelulaEmpregado($sheet, "G{$lin}", "J{$lin}", 'Data de admissão: ' . ($c['dataAdmissao'] ?? ''));
        $sheet->getRowDimension($lin)->setRowHeight(13);
        $lin++;

        return $lin;
    }

    private function escreverCelulaEmpregado(Worksheet $sheet, string $celIni, string $celFim, string $texto): void
    {
        if ($celIni !== $celFim) {
            $sheet->mergeCells("{$celIni}:{$celFim}");
        }
        $sheet->setCellValue($celIni, $texto);
        $s = $sheet->getStyle("{$celIni}:{$celFim}");
        $s->getFont()->setSize(8)->setName('Arial');
        $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
        $s->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
    }

    /** Escreve os dois headers da tabela; retorna próxima linha (primeira de dados). */
    private function escreverHeaderTabela(Worksheet $sheet, int $inicio): int
    {
        $lin1 = $inicio;
        $lin2 = $inicio + 1;

        // Linha 1 do header (com rowspan simulado por merge)
        $grupos = [
            ['A', 'A', 'Dia'],
            ['B', 'B', "Dia da\nSemana"],
            ['G', 'G', "Horas\nTrabalhadas"],
            ['H', 'H', "Horas\nExtras"],
            ['I', 'I', "Banco de\nHoras"],
            ['J', 'J', "Justificativa /\nObservações"],
        ];
        foreach ($grupos as [$c1, $c2, $texto]) {
            $sheet->mergeCells("{$c1}{$lin1}:{$c2}{$lin2}");
            $sheet->setCellValue("{$c1}{$lin1}", $texto);
            $this->estiloCabecalhoTabela($sheet, "{$c1}{$lin1}:{$c2}{$lin2}", $c1 === 'J');
        }

        // "Horários Registrados" colspan 4 (C:F)
        $sheet->mergeCells("C{$lin1}:F{$lin1}");
        $sheet->setCellValue("C{$lin1}", 'Horários Registrados');
        $this->estiloCabecalhoTabela($sheet, "C{$lin1}:F{$lin1}", false);

        // Sub-colunas na linha 2
        $subCols = ['C' => 'Entrada', 'D' => "Início\nIntervalo", 'E' => "Fim\nIntervalo", 'F' => 'Saída'];
        foreach ($subCols as $col => $texto) {
            $sheet->setCellValue("{$col}{$lin2}", $texto);
            $this->estiloCabecalhoTabela($sheet, "{$col}{$lin2}", false);
        }

        $sheet->getRowDimension($lin1)->setRowHeight(22);
        $sheet->getRowDimension($lin2)->setRowHeight(18);

        $sheet->freezePane('A' . ($lin2 + 1));

        return $lin2 + 1;
    }

    private function estiloCabecalhoTabela(Worksheet $sheet, string $range, bool $alinhaEsq): void
    {
        $s = $sheet->getStyle($range);
        $s->getFont()->setBold(true)->setSize(8)->setName('Arial');
        $s->getAlignment()
            ->setHorizontal($alinhaEsq ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF8');
        $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** Escreve as linhas de dados; retorna próxima linha livre após espaço. */
    private function escreverLinhasDados(Worksheet $sheet, array $folhaRows, int $inicio): int
    {
        $lin = $inicio;

        foreach ($folhaRows as $row) {
            $fimSemana = !empty($row['fimSemana']);
            $isFeriado = !empty($row['isFeriado']);

            // Dia e Dia da Semana
            $sheet->setCellValueExplicit("A{$lin}", sprintf('%02d', (int)$row['diaMes']), DataType::TYPE_STRING);

            if ($isFeriado) {
                $sheet->setCellValue("B{$lin}", 'FERIADO');
                $sheet->getStyle("B{$lin}")->getFont()->getColor()->setARGB(self::COR_FERIADO);
                $sheet->getStyle("B{$lin}")->getFont()->setBold(true);
            } else {
                $sheet->setCellValue("B{$lin}", $row['diaSemana']);
            }

            // Horários
            if ($isFeriado) {
                $sheet->mergeCells("C{$lin}:F{$lin}");
                $sheet->setCellValue("C{$lin}", 'FERIADO');
                $sF = $sheet->getStyle("C{$lin}");
                $sF->getFont()->getColor()->setARGB(self::COR_FERIADO);
                $sF->getFont()->setBold(true);
                $sF->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->setCellValue("C{$lin}", $row['entrada'] ? substr($row['entrada'], 0, 5) : '–');
                $sheet->setCellValue("D{$lin}", $row['repouso'] ? substr($row['repouso'], 0, 5) : '–');
                $sheet->setCellValue("E{$lin}", $row['retorno'] ? substr($row['retorno'], 0, 5) : '–');
                $sheet->setCellValue("F{$lin}", $row['saida']   ? substr($row['saida'], 0, 5) : '–');
            }

            // Colunas calculadas
            $sheet->setCellValue("G{$lin}", $row['horasTrabalhadas'] ?? '–');
            $sheet->setCellValue("H{$lin}", $row['horasExtras'] ?? '–');
            $sheet->setCellValue("I{$lin}", $row['bancoHoras'] ?? '–');

            // Justificativa
            $justText = '';
            if ($isFeriado && !empty($row['nomeFeriado'])) {
                $justText = 'Feriado – ' . $row['nomeFeriado'];
            } elseif (!empty($row['justificativa'])) {
                $j = $row['justificativa'];
                if ($j->getStatus() === 'abonado') {
                    $justText = $j->getLabelTipo() ?? '';
                    if ($j->isAbonoParcial() && $j->getHoraInicioAbono() && $j->getHoraFimAbono()) {
                        $justText .= sprintf(' (Parcial: %s–%s)', $j->getHoraInicioAbono()->format('H:i'), $j->getHoraFimAbono()->format('H:i'));
                    }
                } elseif ($j->getStatus() === 'pendente') {
                    $justText = ($j->getLabelTipo() ?? '') . ' (pendente)';
                } else {
                    $justText = ($j->getLabelTipo() ?? '') . ' (rejeitada)';
                }
            }
            $sheet->setCellValue("J{$lin}", $justText);

            // Estilos da linha
            $s = $sheet->getStyle("A{$lin}:J{$lin}");
            $s->getFont()->setSize(8)->setName('Arial');
            $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $s->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$lin}:I{$lin}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("J{$lin}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setIndent(1);

            if ($fimSemana || $isFeriado) {
                $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_CINZA);
            }

            $sheet->getRowDimension($lin)->setRowHeight(13);
            $lin++;
        }

        $sheet->getRowDimension($lin)->setRowHeight(4);

        return $lin + 1;
    }

    /** Escreve os 3 blocos inferiores (resumo / intervalos / observações); retorna próxima linha. */
    private function escreverBlocoInferior(Worksheet $sheet, array $c, int $inicio): int
    {
        $lin = $inicio;
        $sheet->getRowDimension($lin)->setRowHeight(4);
        $lin++;

        // Títulos dos 3 blocos
        $blocos = [
            ['A', 'C', 'RESUMO DO MÊS'],
            ['D', 'G', 'CONTROLE DE INTERVALOS'],
            ['H', 'J', 'OBSERVAÇÕES GERAIS'],
        ];
        foreach ($blocos as [$c1, $c2, $tit]) {
            $sheet->mergeCells("{$c1}{$lin}:{$c2}{$lin}");
            $sheet->setCellValue("{$c1}{$lin}", $tit);
            $sT = $sheet->getStyle("{$c1}{$lin}");
            $sT->getFont()->setBold(true)->setSize(8)->setName('Arial');
            $sT->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sT->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COR_SECAO);
            $sT->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        }
        $sheet->getRowDimension($lin)->setRowHeight(14);
        $lin++;

        // Linhas de conteúdo — máx 5 linhas (6 quando há lançamento de horas pagas na competência)
        $resumo = [
            'Detalhe de Horas Trabalhadas no Mês:  ' . ($c['totalHorasTrabalhadas'] ?? '–'),
            'Total de Horas Extras no Mês:  '         . ($c['totalHorasExtras'] ?? '–'),
            'Saldo do Banco de Horas Anterior:  '     . ($c['saldoBancoAnterior'] ?? '–'),
        ];

        // Mesma notação "+H:MM" das linhas vizinhas de saldo (não o "Xh:MMm" da tela web).
        $horasPagasMinutos = (int) ($c['horasPagasMinutos'] ?? 0);
        if ($horasPagasMinutos !== 0) {
            $absHp = abs($horasPagasMinutos);
            $resumo[] = 'Horas pagas:  ' . ($horasPagasMinutos < 0 ? '-' : '+') . sprintf('%d:%02d', intdiv($absHp, 60), $absHp % 60);
        }

        $resumo[] = 'Saldo do Banco de Horas Atual:  ' . ($c['saldoBancoAtual'] ?? '–');
        $resumo[] = 'Horas a Compensar:  '              . ($c['horasACompensar'] ?? '–');

        $conformeRepouso      = ($c['intrajornadaConforme'] ?? true);
        $conformeInterjornada = ($c['interjornadaConforme'] ?? true);
        $minimoR  = $c['minimoRepousoTexto'] ?? '1h';
        $minimoI  = $c['minimoInterjornadaTexto'] ?? '11h';
        $feriados = ($c['feriadosTrabalhados'] ?? 0);
        $fds      = ($c['finaisSemanasTrabalhados'] ?? 0);

        $intervalos = [
            'Intervalo Intrajornada (mín. ' . $minimoR . '):  ' . ($conformeRepouso ? '✓ Conforme' : '✗ Não Conforme'),
            'Intervalo Interjornada (mín. ' . $minimoI . '):  ' . ($conformeInterjornada ? '✓ Conforme' : '✗ Não Conforme'),
            'Descanso Semanal Remunerado:  ✓ Conforme',
            'Trabalho em Feriados:  ' . $feriados . ' dia' . ($feriados !== 1 ? 's' : ''),
            'Trabalho em Finais de Semana:  ' . $fds . ' dia' . ($fds !== 1 ? 's' : ''),
        ];

        $observacoes = [
            '• As horas extras foram autorizadas previamente pela gestão e serão compensadas conforme acordo de banco de horas.',
            '• Feriados trabalhados serão compensados.',
            '• Registros realizados por sistema eletrônico de ponto em conformidade com a Portaria MTP nº 671/2021.',
        ];

        $totalLinhas = max(count($resumo), count($intervalos), count($observacoes));

        for ($i = 0; $i < $totalLinhas; $i++) {
            // Resumo
            $textoR = $resumo[$i] ?? '';
            $sheet->mergeCells("A{$lin}:C{$lin}");
            $sheet->setCellValue("A{$lin}", $textoR);
            $sR = $sheet->getStyle("A{$lin}:C{$lin}");
            $sR->getFont()->setSize(8)->setName('Arial');
            $sR->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1)->setWrapText(false);
            $sR->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);

            // Intervalos — colorir ✓/✗
            $textoI = $intervalos[$i] ?? '';
            $sheet->mergeCells("D{$lin}:G{$lin}");
            $sheet->setCellValue("D{$lin}", $textoI);
            $sI = $sheet->getStyle("D{$lin}:G{$lin}");
            $sI->getFont()->setSize(8)->setName('Arial');
            $sI->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1)->setWrapText(false);
            $sI->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            if (str_contains($textoI, '✓')) {
                $sI->getFont()->getColor()->setARGB(self::COR_CONFORME);
            } elseif (str_contains($textoI, '✗')) {
                $sI->getFont()->getColor()->setARGB(self::COR_FERIADO);
            }

            // Observações
            $textoO = $observacoes[$i] ?? '';
            $sheet->mergeCells("H{$lin}:J{$lin}");
            $sheet->setCellValue("H{$lin}", $textoO);
            $sO = $sheet->getStyle("H{$lin}:J{$lin}");
            $sO->getFont()->setSize(8)->setName('Arial');
            $sO->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setIndent(1)->setWrapText(true);
            $sO->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);

            $sheet->getRowDimension($lin)->setRowHeight($i === 0 ? 28 : 13);
            $lin++;
        }

        return $lin;
    }

    /** Escreve seção de assinaturas; retorna próxima linha. */
    private function escreverAssinaturas(Worksheet $sheet, array $c, int $inicio): int
    {
        $lin = $inicio + 1;

        // Declaração
        $sheet->mergeCells("A{$lin}:J{$lin}");
        $sheet->setCellValue("A{$lin}", 'Declaro que os registros acima representam fielmente minha jornada de trabalho.');
        $sheet->getStyle("A{$lin}")->getFont()->setSize(8)->setName('Arial');
        $sheet->getStyle("A{$lin}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($lin)->setRowHeight(14);
        $lin++;

        // Local / Data
        $sheet->mergeCells("A{$lin}:J{$lin}");
        $sheet->setCellValue("A{$lin}", 'Local: ___________________________________          Data: ____/____/' . ($c['anoAssinatura'] ?? ''));
        $sheet->getStyle("A{$lin}")->getFont()->setSize(8)->setName('Arial');
        $sheet->getRowDimension($lin)->setRowHeight(30);
        $lin++;

        // Assinatura Empregado (esq)
        $sheet->mergeCells("A{$lin}:E{$lin}");
        $sheet->setCellValue("A{$lin}", 'Assinatura do Empregado');
        $sE = $sheet->getStyle("A{$lin}");
        $sE->getFont()->setBold(false)->setSize(8)->setName('Arial');
        $sE->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sE->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($lin)->setRowHeight(14);

        // Assinatura Responsável (dir)
        $sheet->mergeCells("F{$lin}:J{$lin}");
        $sheet->setCellValue("F{$lin}", 'Assinatura do Responsável');
        $sR = $sheet->getStyle("F{$lin}");
        $sR->getFont()->setBold(false)->setSize(8)->setName('Arial');
        $sR->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sR->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $lin++;

        // Nome Empregado
        $sheet->mergeCells("A{$lin}:E{$lin}");
        $sheet->setCellValue("A{$lin}", $c['nomeUsuario'] ?? '');
        $sheet->getStyle("A{$lin}")->getFont()->setBold(true)->setSize(8)->setName('Arial');
        $sheet->getStyle("A{$lin}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($lin)->setRowHeight(13);

        // Nome Responsável
        $sheet->mergeCells("F{$lin}:J{$lin}");
        $sheet->setCellValue("F{$lin}", $c['responsavelAssinatura'] ?? '');
        $sheet->getStyle("F{$lin}")->getFont()->setBold(true)->setSize(8)->setName('Arial');
        $sheet->getStyle("F{$lin}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $lin++;

        return $lin;
    }

    private function escreverRodape(Worksheet $sheet, int $linha): void
    {
        $lin = $linha + 1;

        $rodape1 = 'Base Legal: Art. 74, §2º da CLT  |  Portaria MTP nº 671/2021  |  Art. 13 da Portaria nº 3.626/91';
        $rodape2 = '(Esta folha de ponto substitui o Quadro de Horário de Trabalho, inclusive o de menores.)';

        $sheet->mergeCells("A{$lin}:J{$lin}");
        $sheet->setCellValue("A{$lin}", $rodape1 . '  ' . $rodape2);
        $s = $sheet->getStyle("A{$lin}");
        $s->getFont()->setSize(7)->setName('Arial');
        $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $s->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($lin)->setRowHeight(22);
    }
}
