<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Master\Exception\CustoRecusado;

/**
 * CSV da seção Custos (desenho `csCsv`/`csImport`, spec master-custos D7).
 * Exporta com `;`, BOM UTF-8 e valor com vírgula, colunas Mês, Categoria, Fornecedor, Valor, Origem, Observação.
 * Importa com Mês (AAAA-MM), Categoria, Fornecedor e Valor obrigatórios (Origem é ignorada: tudo entra como importado;
 * Observação opcional). Aceita `;`, tabulação ou vírgula, com ou sem BOM, em UTF-8 ou Windows-1252 (Excel).
 * Categoria desconhecida vira "Outros"; linha sem as 4 colunas ou com valor <= 0 é ignorada e contada.
 */
final class ArquivoDeCustosCsv
{
    public const TAMANHO_MAXIMO = 1048576;
    public const LINHAS_MAXIMAS = 1000;

    private const CABECALHO = ['Mês', 'Categoria', 'Fornecedor', 'Valor', 'Origem', 'Observação'];

    /**
     * @param list<LancamentoDeCusto> $lancamentos
     * @param array<string, string>   $rotulosDeCategoria código => rótulo
     * @param array<string, string>   $rotulosDeOrigem    código => rótulo
     */
    public static function gerar(array $lancamentos, array $rotulosDeCategoria, array $rotulosDeOrigem): string
    {
        $linhas = [self::linha(self::CABECALHO)];
        foreach ($lancamentos as $l) {
            $linhas[] = self::linha([
                $l->getMes(),
                $rotulosDeCategoria[$l->getCategoria()->value] ?? $l->getCategoria()->value,
                $l->getFornecedor() ?? '',
                number_format($l->getValorCentavos() / 100, 2, ',', ''),
                $rotulosDeOrigem[$l->getOrigem()->value] ?? $l->getOrigem()->value,
                $l->getObservacao() ?? '',
            ]);
        }

        return "\u{FEFF}" . implode("\r\n", $linhas) . "\r\n";
    }

    /**
     * @param array<string, string> $rotulosDeCategoria código => rótulo
     *
     * @return array{linhas: list<array{mes: string, categoria: CategoriaDeCusto, valorCentavos: int, fornecedor: ?string, observacao: ?string, origem: OrigemDoCusto}>, ignoradas: int}
     */
    public static function ler(string $conteudo, string $mesPadrao, array $rotulosDeCategoria): array
    {
        if (strlen($conteudo) > self::TAMANHO_MAXIMO) {
            throw new CustoRecusado('O arquivo passa de 1 MB.');
        }
        $texto = self::emUtf8($conteudo);
        $brutas = preg_split('/\r\n|\n|\r/', $texto) ?: [];
        $brutas = array_values(array_filter($brutas, static fn (string $l): bool => trim($l) !== ''));
        array_shift($brutas); // cabeçalho
        if (count($brutas) > self::LINHAS_MAXIMAS) {
            throw new CustoRecusado('O arquivo passa de ' . self::LINHAS_MAXIMAS . ' linhas.');
        }

        $porRotulo = [];
        foreach (CategoriaDeCusto::cases() as $c) {
            $porRotulo[mb_strtolower($c->value)] = $c;
            $porRotulo[mb_strtolower($rotulosDeCategoria[$c->value] ?? $c->value)] = $c;
        }
        $separador = self::separador($texto);

        $lidas = [];
        $ignoradas = 0;
        foreach ($brutas as $bruta) {
            $p = str_getcsv($bruta, $separador, '"', '');
            $valor = count($p) >= 4 ? ValorEmReais::paraCentavos($p[3]) : null;
            if ($valor === null) {
                ++$ignoradas;
                continue;
            }
            $mes = trim($p[0]);
            $fornecedor = trim($p[2]);
            $observacao = trim($p[5] ?? '');
            $lidas[] = [
                'mes' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1 ? $mes : $mesPadrao,
                'categoria' => $porRotulo[mb_strtolower(trim($p[1]))] ?? CategoriaDeCusto::Outros,
                'valorCentavos' => $valor,
                'fornecedor' => $fornecedor === '' ? null : mb_substr($fornecedor, 0, LancamentoDeCusto::FORNECEDOR_MAXIMO),
                'observacao' => $observacao === '' ? null : mb_substr($observacao, 0, LancamentoDeCusto::OBSERVACAO_MAXIMA),
                'origem' => OrigemDoCusto::Importado,
            ];
        }

        return ['linhas' => $lidas, 'ignoradas' => $ignoradas];
    }

    private static function emUtf8(string $conteudo): string
    {
        if (str_starts_with($conteudo, "\u{FEFF}")) {
            $conteudo = substr($conteudo, 3);
        }

        return mb_check_encoding($conteudo, 'UTF-8') ? $conteudo : mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }

    /** Pelo cabeçalho: `;` do nosso arquivo e do Excel brasileiro, tabulação ou, por último, vírgula. */
    private static function separador(string $texto): string
    {
        $cabecalho = strtok($texto, "\r\n") ?: '';
        if (str_contains($cabecalho, ';')) {
            return ';';
        }

        return str_contains($cabecalho, "\t") ? "\t" : ',';
    }

    /**
     * @param list<string> $campos
     */
    private static function linha(array $campos): string
    {
        return implode(';', array_map(static function (string $v): string {
            // Planilha executa texto que começa com = + - @ (injeção de fórmula): o apóstrofo o torna texto.
            if ($v !== '' && str_contains('=+-@', $v[0])) {
                $v = "'" . $v;
            }

            return '"' . str_replace('"', '""', $v) . '"';
        }, $campos));
    }
}
