<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Regra de localização de UMA pessoa no mapa "Onde estamos atuando" (desenho 05 - Perfis 1.0,
 * `Component.local()`; ESPEC_DADOS_REAIS_MAPA.md §2.1).
 *
 * Escolha do vínculo: primeiro o escritório em que a pessoa é primária; empate, o de acesso mais
 * recente; entre eles, o primeiro cuja lotação esteja no formato "Cidade/UF".
 *
 * Ordem de prioridade:
 *  1. lotação do vínculo escolhido no formato "Cidade/UF" (aceita "/", "-" ou "–"): cidade e UF;
 *  2. sem isso, a UF da OAB (conta no estado, sem cidade);
 *  3. sem OAB, o estado do escritório do vínculo (`Tenant.estado`);
 *  4. nada disso: sem localização (contado à parte, fora do mapa).
 *
 * Diferença deliberada em relação ao protótipo: se a lotação tem o formato "Algo/XX" mas XX não é
 * uma UF (ex.: "Setor/RH"), o protótipo descartava a pessoa; aqui a lotação é ignorada e a regra
 * segue para a OAB e o escritório, que é o que a ordem de prioridade descreve.
 */
final class LocalizacaoDaPessoa
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    private const LOTACAO_COM_UF = '/\/\s*[A-Za-z]{2}\s*$/u';
    private const CIDADE_E_UF    = '/^\s*(.+?)\s*[\/\-–]\s*([A-Za-z]{2})\s*$/u';

    /**
     * @param array{oabUf?: ?string, vinculos: list<array{tenantId: int, lotacao: string, primario: bool, ultimoAcesso: string}>} $pessoa
     * @param array<int, ?string>                                                                                                 $estadoPorEscritorio tenantId => Tenant.estado
     *
     * @return array{uf: string, cidade: string, fonte: string}|null
     */
    public static function localizar(array $pessoa, array $estadoPorEscritorio): ?array
    {
        $vinculos = $pessoa['vinculos'];
        usort($vinculos, static function (array $a, array $b): int {
            if ($a['primario'] !== $b['primario']) {
                return $a['primario'] ? -1 : 1;
            }

            return strcmp($b['ultimoAcesso'], $a['ultimoAcesso']);
        });

        $escolhido = $vinculos[0] ?? null;
        foreach ($vinculos as $v) {
            if (preg_match(self::LOTACAO_COM_UF, $v['lotacao']) === 1) {
                $escolhido = $v;
                break;
            }
        }

        if ($escolhido !== null && preg_match(self::CIDADE_E_UF, $escolhido['lotacao'], $m) === 1) {
            $uf = strtoupper($m[2]);
            if (in_array($uf, self::UFS, true)) {
                return ['uf' => $uf, 'cidade' => self::nomeDeLugar($m[1]), 'fonte' => 'lotação'];
            }
        }

        $ufOab = self::uf($pessoa['oabUf'] ?? null);
        if ($ufOab !== null) {
            return ['uf' => $ufOab, 'cidade' => '', 'fonte' => 'OAB'];
        }

        $ufEscritorio = $escolhido !== null ? self::uf($estadoPorEscritorio[$escolhido['tenantId']] ?? null) : null;
        if ($ufEscritorio !== null) {
            return ['uf' => $ufEscritorio, 'cidade' => '', 'fonte' => 'escritório'];
        }

        return null;
    }

    public const TAMANHO_MAXIMO_DO_NOME = 80;

    /**
     * Nome de cidade seguro para sair do servidor (defesa em profundidade contra XSS armazenado: a
     * lotação e o `Tenant.cidade` são texto livre digitado em escritório). Remove `< > " ' \``,
     * caracteres de controle e espaços repetidos, e corta em {@see TAMANHO_MAXIMO_DO_NOME}.
     */
    public static function nomeDeLugar(?string $valor): string
    {
        $limpo = preg_replace(['/\s+/u', '/[<>"\'`]/u', '/[\p{Cc}\p{Cf}]/u', '/ {2,}/'], [' ', '', '', ' '], (string) $valor) ?? '';

        return mb_substr(trim($limpo), 0, self::TAMANHO_MAXIMO_DO_NOME);
    }

    /** Sigla válida em maiúsculas, ou nulo. */
    public static function uf(?string $valor): ?string
    {
        $uf = strtoupper(trim((string) $valor));

        return in_array($uf, self::UFS, true) ? $uf : null;
    }
}
