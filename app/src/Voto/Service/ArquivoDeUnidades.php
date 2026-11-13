<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ExcecaoDeLeituraDaPlanilha;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UploadedFile;

final class ArquivoDeUnidades
{
    public const TAMANHO_MAXIMO = 10 * 1024 * 1024;

    /** @return list<list<string>> */
    public function ler(UploadedFile $arquivo): array
    {
        if (!$arquivo->isValid() || $arquivo->getSize() > self::TAMANHO_MAXIMO) {
            throw new OperacaoInvalidaNaAssembleiaException('O arquivo está inválido ou ultrapassa 10 MB.');
        }
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        if ('csv' === $extensao) {
            return $this->lerCsv($arquivo->getPathname());
        }
        try {
            $tipo = IOFactory::identify($arquivo->getPathname());
        } catch (ExcecaoDeLeituraDaPlanilha) {
            throw new OperacaoInvalidaNaAssembleiaException('O arquivo não é uma planilha válida.');
        }
        if ('xlsx' !== $extensao || 'Xlsx' !== $tipo) {
            throw new OperacaoInvalidaNaAssembleiaException('Envie um arquivo .xlsx ou .csv válido.');
        }
        try {
            $planilha = IOFactory::load($arquivo->getPathname());
        } catch (ExcecaoDeLeituraDaPlanilha) {
            throw new OperacaoInvalidaNaAssembleiaException('O arquivo não é uma planilha XLSX válida.');
        }
        try {
            $aba = $planilha->getSheet(0);
            if ($aba->getHighestDataRow() > LeitorDaListaDeUnidades::LIMITE_DE_LINHAS
                || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($aba->getHighestDataColumn()) > 6) {
                throw new OperacaoInvalidaNaAssembleiaException('A planilha deve ter até 3000 linhas e 6 colunas.');
            }
            $linhas = $aba->toArray(null, false, false, false);

            return array_map(self::textoEmColunas(...), $linhas);
        } finally {
            $planilha->disconnectWorksheets();
        }
    }

    public function modelo(): StreamedResponse
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Unidades');
        $aba->fromArray([['Unidade', 'Bloco', 'Situação', 'Fração', 'Responsável', 'E-mail']], null, 'A1');
        $aba->getColumnDimension('A')->setWidth(18);
        $aba->getColumnDimension('B')->setWidth(18);
        $aba->getColumnDimension('C')->setWidth(20);
        $aba->getColumnDimension('D')->setWidth(14);
        $aba->getColumnDimension('E')->setWidth(30);
        $aba->getColumnDimension('F')->setWidth(32);
        $resposta = new StreamedResponse(static function () use ($planilha): void {
            try {
                (new Xlsx($planilha))->save('php://output');
            } finally {
                $planilha->disconnectWorksheets();
            }
        });
        $resposta->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $resposta->headers->set('Content-Disposition', (new ResponseHeaderBag())->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'modelo-unidades.xlsx',
        ));

        return $resposta;
    }

    /** @return list<list<string>> */
    private function lerCsv(string $caminho): array
    {
        $arquivo = new \SplFileObject($caminho, 'r');
        $primeira = (string) $arquivo->fgets();
        $pontoVirgula = str_getcsv($primeira, ';', '"', '\\');
        $virgula = str_getcsv($primeira, ',', '"', '\\');
        $separador = \count($virgula) > \count($pontoVirgula) ? ',' : ';';
        $arquivo->rewind();
        $linhas = [];
        while (!$arquivo->eof()) {
            $colunas = $arquivo->fgetcsv($separador, '"', '\\');
            if (false === $colunas || [null] === $colunas) {
                continue;
            }
            if (\count($colunas) > 6) {
                throw new OperacaoInvalidaNaAssembleiaException('O CSV deve ter no máximo 6 colunas.');
            }
            $linhas[] = self::textoEmColunas($colunas);
            if (\count($linhas) > LeitorDaListaDeUnidades::LIMITE_DE_LINHAS) {
                throw new OperacaoInvalidaNaAssembleiaException('Envie no máximo 3000 linhas por vez.');
            }
        }

        return $linhas;
    }

    /** @param list<mixed> $valores
     *  @return list<string>
     */
    private static function textoEmColunas(array $valores): array
    {
        return array_map(static function (mixed $valor): string {
            if (null === $valor) {
                return '';
            }
            if (!is_scalar($valor)) {
                throw new OperacaoInvalidaNaAssembleiaException('A planilha contém um valor que não pode ser lido.');
            }
            $texto = (string) $valor;
            if (str_starts_with(ltrim($texto), '=')) {
                throw new OperacaoInvalidaNaAssembleiaException('Remova as fórmulas da planilha antes de importar.');
            }

            return $texto;
        }, $valores);
    }
}
