<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

/**
 * Conversão de célula para os leitores do espelho — o lugar ÚNICO onde texto de planilha vira número
 * (SPEC quatro-relatórios §3.2, INV-Q9).
 *
 * 🔴 **Existe por causa do espaço não-quebrável.** A célula de desconto dos relatórios de acordos e
 * receitas não é um número: é o texto `- 15,00`, com **U+00A0** entre o sinal e o algarismo (bytes
 * conferidos: `2d c2a0 31 35 2c 30 30`). `trim()` não o remove, e `\s` em PCRE sem `/u` também não —
 * então a conversão ingênua devolve `null`, o desconto **some da soma** e **nada falha**.
 *
 * Na primeira medição desta frente isso fez o portão dos acordos parecer recusar 47 de 325 abas.
 * Depois de tratar o U+00A0, fecham 381 de 392. As 47 nunca existiram.
 *
 * ⚠️ **Todo leitor novo do espelho usa este trait.** Reimplementar a conversão "só para este caso" é
 * reabrir a armadilha num lugar onde ninguém vai procurá-la.
 */
trait LeCelulasDoEspelho
{
    /** Espaços que a planilha usa e que `trim()` não remove. */
    private const ESPACOS_INVISIVEIS = ["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"];

    /**
     * Texto da célula, ou `null` se vazia. O U+00A0 vira espaço comum **antes** do `trim`, senão
     * uma célula que só tem um espaço não-quebrável passa por preenchida.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_float($valor) || is_int($valor)) {
            return (string) $valor;
        }

        if (!is_string($valor)) {
            return null;
        }

        $texto = trim(str_replace(self::ESPACOS_INVISIVEIS, ' ', $valor));

        return $texto === '' ? null : $texto;
    }

    /**
     * Centavos, aceitando as duas formas em que a contabilidade escreve dinheiro:
     * número puro (inadimplência) e texto pt-BR `1.234,56` / `- 15,00` (acordos e receitas).
     *
     * Célula vazia vira `null`, NÃO zero — vazio e zero são coisas diferentes na planilha, e achatar
     * os dois já seria interpretar (INV-L3). O traço solto `-`, que a planilha usa para "não se
     * aplica", também é `null` e não zero.
     */
    private function centavos(mixed $valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            return (int) round($valor * 100);
        }

        if (!is_string($valor)) {
            return null;
        }

        $limpo = str_replace([...self::ESPACOS_INVISIVEIS, ' ', "\t"], '', $valor);

        if ($limpo === '' || $limpo === '-') {
            return null;
        }

        // 🔴 A célula de dinheiro em texto é pt-BR — e só. `1.234,56`, `-15,00`, `100`.
        //
        // Sem esta guarda, `str_replace('.','')` trata o ponto como separador de MILHAR e converte
        // formato en-US em número 100× maior, **calado**: `'1234.56'` vira `123456` e depois
        // **R$ 123.456,00** — cem vezes o valor certo. (A primeira versão deste comentário dizia
        // "R$ 12.345,60"; a revisão pegou, e um comentário que erra por 10× o defeito que descreve é
        // pior do que comentário nenhum.)
        //
        // Hoje não morde: nos arquivos reais o dinheiro chega como `double` nativo, e o texto pt-BR só
        // aparece nos acordos. Mas a distorção seria UNIFORME — linhas e totalizador escalariam
        // juntos, o portão fecharia, e o espelho ficaria 100× errado com cara de verdade.
        //
        // Recusar devolve `null`, e `null` numa coluna de dinheiro derruba o portão: falha alta, não
        // silenciosa. O `$bruto` guarda a célula íntegra para quem for investigar.
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/', $limpo) !== 1) {
            return null;
        }

        // Separador de milhar do pt-BR sai antes da vírgula decimal virar ponto; a ordem importa,
        // senão "1.234,56" viraria "1.234.56", que não é numérico.
        $normalizado = str_replace(',', '.', str_replace('.', '', $limpo));

        return is_numeric($normalizado) ? (int) round(((float) $normalizado) * 100) : null;
    }

    /** Data `dd/mm/aaaa`, recusando data impossível (`31/02/2026` não vira 03/03). */
    private function data(mixed $valor): ?\DateTimeImmutable
    {
        $texto = $this->texto($valor);

        if ($texto === null || preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) !== 1) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!d/m/Y', $texto);

        return ($data === false || $data->format('d/m/Y') !== $texto) ? null : $data;
    }

    /**
     * O valor de um par `Rótulo: valor` escrito dentro de UMA célula — a forma do cabeçalho das abas
     * de acordo (`Data base: 23/03/2026`, `Valor final acordado: 9.515,00`).
     *
     * @param list<mixed> $celulas
     */
    private function valorDoRotulo(array $celulas, string $rotuloRegex): ?string
    {
        foreach ($celulas as $celula) {
            $texto = $this->texto($celula);

            if ($texto !== null && preg_match('/^' . $rotuloRegex . ':\s*(.*)$/u', $texto, $m) === 1) {
                $valor = trim($m[1]);

                return $valor === '' ? null : $valor;
            }
        }

        return null;
    }

    /**
     * Todas as células de uma linha como texto, na ordem do arquivo — o `$bruto` que prova que a
     * conversão acertou quando alguém duvidar do número.
     *
     * @param list<mixed> $celulas
     *
     * @return list<string>
     */
    private function bruto(array $celulas): array
    {
        return array_map(fn (mixed $c): string => $this->texto($c) ?? '', $celulas);
    }

    /** @param list<mixed> $celulas */
    private function linhaVazia(array $celulas): bool
    {
        foreach ($celulas as $celula) {
            if ($this->texto($celula) !== null) {
                return false;
            }
        }

        return true;
    }
}
