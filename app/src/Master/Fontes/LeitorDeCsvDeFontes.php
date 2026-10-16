<?php

declare(strict_types=1);

namespace App\Master\Fontes;

/**
 * Lê os dois CSV que o Master pode enviar pelo menu ⋮ do mapa (os mesmos modelos que ele baixa):
 *
 *  - tabela de dados públicos: `tipo;codigo_ibge;uf;nome;populacao;advogados` (protótipo
 *    `mapa-dados.js`, "Baixar tabela (CSV)" / "Enviar tabela atualizada");
 *  - Quadro da Advocacia: `SECCIONAL` seguido das 14 colunas na ordem de {@see LeitorQuadroOab}
 *    ("Baixar planilha OAB (CSV)" / "Atualizar OAB por arquivo (CSV)").
 *
 * Separador `;` ou `,`; números nos formatos de {@see numero()}.
 */
final class LeitorDeCsvDeFontes
{
    public const CABECALHO_OAB = [
        'SECCIONAL', 'Advogado(a)', 'Estagiário(a)', 'Suplementar', 'Consultor Estrangeiro',
        'Advogados Feminino', 'Advogados Masculino',
        'Até 25 anos Feminino', 'Até 25 anos Masculino', '26 a 40 anos Feminino', '26 a 40 anos Masculino',
        '41 a 59 anos Feminino', '41 a 59 anos Masculino', '60 anos ou mais Feminino', '60 anos ou mais Masculino',
    ];

    private const UF_POR_SIGLA = ClienteIbge::CODIGO_UF;

    /**
     * @return array{uf: array<string, array{pop?: int, adv?: int}>, mun: array<string, array<string, int>>, estados: int, municipios: int}
     *
     * @throws ArquivoNaoReconhecido
     */
    public function tabela(string $conteudo): array
    {
        [$cabecalho, $linhas] = self::linhas($conteudo);
        $ix = static function (string $chave) use ($cabecalho): int {
            foreach ($cabecalho as $i => $c) {
                if (str_contains($c, $chave)) {
                    return $i;
                }
            }

            return -1;
        };
        $iTipo = $ix('tipo');
        $iCodigo = $ix('codigo');
        $iUf = $ix('uf');
        $iPop = $ix('popula');
        $iAdv = $ix('advog');
        if ($iPop < 0 && $iAdv < 0) {
            throw new ArquivoNaoReconhecido('Tabela não reconhecida. Use o modelo baixado pelo menu.');
        }

        $saida = ['uf' => [], 'mun' => [], 'estados' => 0, 'municipios' => 0];
        foreach ($linhas as $c) {
            $codigo = $iCodigo >= 0 ? preg_replace('/\D/', '', $c[$iCodigo] ?? '') : '';
            if (($codigo === '' || $codigo === null) && $iUf >= 0) {
                $codigo = (string) (self::UF_POR_SIGLA[strtoupper($c[$iUf] ?? '')] ?? '');
            }
            if ($codigo === '' || $codigo === null) {
                continue;
            }
            $tipo = $iTipo >= 0 ? strtolower($c[$iTipo] ?? '') : '';
            $pop = $iPop >= 0 ? self::numero($c[$iPop] ?? '') : null;
            $adv = $iAdv >= 0 ? self::numero($c[$iAdv] ?? '') : null;

            // Só códigos IBGE válidos: UF (2 dígitos conhecidos) ou município (7 dígitos de uma UF conhecida).
            $ehEstado = str_starts_with($tipo, 'est') || strlen($codigo) === 2;
            if (strlen($codigo) !== ($ehEstado ? 2 : 7) || !in_array((int) substr($codigo, 0, 2), self::UF_POR_SIGLA, true)) {
                continue;
            }

            if ($ehEstado) {
                $valores = array_filter(['pop' => $pop, 'adv' => $adv], static fn (?int $v): bool => $v !== null && $v > 0);
                if ($valores !== []) {
                    $saida['uf'][$codigo] = $valores;
                }
                $saida['estados']++;
                continue;
            }

            if ($pop !== null && $pop > 0) {
                $saida['mun'][substr($codigo, 0, 2)][$codigo] = $pop;
            }
            $saida['municipios']++;
        }

        return $saida;
    }

    /**
     * @return array{uf: array<string, list<int|null>>, totais: array<int, int|null>} UF => 14 posições
     *         (nula = não veio no arquivo) e, se a planilha tiver a linha TOTAL, os totais por posição
     *
     * @throws ArquivoNaoReconhecido
     */
    public function quadroOab(string $conteudo): array
    {
        [$cabecalho, $linhas] = self::linhas($conteudo);
        if (($cabecalho[0] ?? '') !== 'seccional') {
            throw new ArquivoNaoReconhecido('Não encontrei a tabela da OAB no arquivo. Use o modelo baixado pelo menu.');
        }

        $saida = [];
        $totais = [];
        foreach ($linhas as $c) {
            $sigla = strtoupper(trim($c[0] ?? ''));
            if (!isset(self::UF_POR_SIGLA[$sigla]) && $sigla !== 'TOTAL') {
                continue;
            }
            $valores = [];
            for ($i = 1; $i <= 14; $i++) {
                $valores[] = self::numero($c[$i] ?? '');
            }
            if ($sigla === 'TOTAL') {
                $totais = $valores;
                continue;
            }
            $saida[$sigla] = $valores;
        }

        if ($saida === []) {
            throw new ArquivoNaoReconhecido('Não encontrei a tabela da OAB no arquivo. Use o modelo baixado pelo menu.');
        }

        return ['uf' => $saida, 'totais' => $totais];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private static function linhas(string $conteudo): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;
        $brutas = array_values(array_filter(preg_split('/\r\n|\n|\r/', $texto) ?: [], static fn (string $l): bool => trim($l) !== ''));
        if ($brutas === []) {
            throw new ArquivoNaoReconhecido('Tabela não reconhecida. Use o modelo baixado pelo menu.');
        }

        $separador = str_contains($brutas[0], ';') ? ';' : ',';
        $quebrar = static fn (string $l): array => array_map('trim', str_getcsv($l, $separador, '"', ''));
        $cabecalho = array_map(static fn (string $c): string => mb_strtolower($c), $quebrar($brutas[0]));

        return [$cabecalho, array_map($quebrar, array_slice($brutas, 1))];
    }

    /**
     * Número da planilha, em qualquer dos formatos comuns, sempre como inteiro (decimal descartado):
     * "4250", "4.250", "46,024,937" (milhar com . ou , em grupos de 3), "17223547.5" (Excel cru),
     * "17.223.547,50" e "46,024,937.25". Vazio = nulo. Qualquer outra forma (grupos irregulares,
     * "1234.567", separadores misturados fora do padrão) é AMBÍGUA e recusa o arquivo inteiro: um
     * número mal lido mudaria o mapa sem ninguém perceber.
     *
     * @throws ArquivoNaoReconhecido
     */
    public static function numero(string $texto): ?int
    {
        $t = str_replace([' ', "\u{00A0}"], '', trim($texto));
        if ($t === '') {
            return null;
        }

        $padroes = [
            '/^(\d+)$/'                                   => static fn (array $m): string => $m[1],
            '/^(\d{1,3}(?:\.\d{3})+)$/'                     => static fn (array $m): string => str_replace('.', '', $m[1]),
            '/^(\d{1,3}(?:,\d{3})+)$/'                      => static fn (array $m): string => str_replace(',', '', $m[1]),
            '/^(\d{1,3}(?:\.\d{3})+),\d+$/'                 => static fn (array $m): string => str_replace('.', '', $m[1]),
            '/^(\d{1,3}(?:,\d{3})+)\.\d+$/'                 => static fn (array $m): string => str_replace(',', '', $m[1]),
            '/^(\d+)[.,](\d{1,2}|\d{4,})$/'                => static fn (array $m): string => $m[1],
            '/^(\d{4,})[.,]\d{3}$/'                        => null,
        ];

        foreach ($padroes as $regex => $converter) {
            if (preg_match($regex, $t, $m) === 1) {
                if ($converter === null) {
                    break;
                }

                return (int) $converter($m);
            }
        }

        throw new ArquivoNaoReconhecido(sprintf('Número ambíguo na planilha: "%s". Use números inteiros, como no modelo baixado pelo menu.', mb_substr($t, 0, 30)));
    }
}
