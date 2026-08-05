<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * A régua que separa a coluna `Unidade` da contábil em **identificação** (o que identifica o imóvel, e
 * é a chave do `ObjetoCobranca` na carteira) e **metadata** (o texto entre parênteses, que descreve
 * unidades agregadas e não identifica nada).
 *
 * `01-01 (05-03,06-01,06-02,06-03 e 06-04)` → `01-01` + `05-03,06-01,06-02,06-03 e 06-04`.
 *
 * Existe como classe própria porque os TRÊS relatórios trazem a mesma coluna no mesmo formato — medido
 * em 07/08: o texto bruto da `Unidade:` do relatório de Acordos é idêntico ao da Inadimplência,
 * inclusive na forma com parênteses (26 das 325 abas da TOP LIFE 1). Antes disto a régua era um método
 * privado copiado em TRÊS adapters (inadimplência, receitas e cadastro de condôminos); o item 5 seria a
 * quarta cópia, e régua de casamento de unidade divergindo entre relatórios casaria dívida no objeto
 * errado.
 *
 * ⚠️ O adapter de cadastro descartava a metadata (devolvia só a identificação) — a diferença é o
 * DESCARTE do segundo elemento, não a régua, que era idêntica caractere a caractere nos três.
 */
final class IdentificacaoDaUnidade
{
    /** @return array{0: string, 1: string|null} identificação e metadata (null quando não há parênteses) */
    public static function separar(string $unidade): array
    {
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $unidade, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($unidade), null];
    }
}
