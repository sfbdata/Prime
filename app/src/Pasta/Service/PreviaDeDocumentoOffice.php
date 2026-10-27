<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as LeitorXlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Writer\Html as EscritorHtmlPlanilha;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Writer\HTML as HtmlDeTexto;

/**
 * Converte DOCX e XLSX em HTML só para LER na tela (visualizador do Peticionar, F9).
 *
 * O HTML sai para um iframe sob CSP restrita (sem script, sem rede): o conteúdo do arquivo é do
 * usuário e nunca é confiável. Planilha: no máximo {@see self::LINHAS} linhas e {@see self::COLUNAS}
 * colunas por aba, lidas só como dado (sem fórmula recalculada, sem macro).
 */
final class PreviaDeDocumentoOffice
{
    public const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const LINHAS  = 500;
    public const COLUNAS = 40;

    public static function aceita(string $mime): bool
    {
        return $mime === self::MIME_DOCX || $mime === self::MIME_XLSX;
    }

    /**
     * @param string $caminho arquivo temporário já gravado
     *
     * @throws \Throwable quando o arquivo não é um Office válido (quem chama mostra o aviso)
     */
    public function converter(string $caminho, string $mime): string
    {
        return $mime === self::MIME_XLSX ? $this->planilha($caminho) : $this->texto($caminho);
    }

    private function texto(string $caminho): string
    {
        $documento = IOFactory::load($caminho, 'Word2007');

        return (new HtmlDeTexto($documento))->getContent();
    }

    private function planilha(string $caminho): string
    {
        $leitor = new LeitorXlsx();
        $leitor->setReadDataOnly(true);
        $leitor->setReadFilter(new class (self::LINHAS, self::COLUNAS) implements IReadFilter {
            public function __construct(private readonly int $linhas, private readonly int $colunas)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->linhas
                    && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->colunas;
            }
        });

        $planilha = $leitor->load($caminho);
        $escritor = new EscritorHtmlPlanilha($planilha);
        $escritor->writeAllSheets();
        $html = $escritor->generateHTMLAll();
        $planilha->disconnectWorksheets();

        return $html;
    }
}
