<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

/**
 * Mascara dados pessoais ANTES de o texto sair do escritório (D4): CPF, CNPJ, telefone e e-mail
 * viram [CPF], [CNPJ], [TEL] e [EMAIL]. Regexes de referência: `bluejus-docs.js` do Designer.
 *
 * O número CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) é PROTEGIDO antes de qualquer máscara e restaurado
 * depois — tem 20 dígitos e, sem a proteção, um pedaço dele casaria com telefone ou CPF e o
 * modelo perderia a referência do processo.
 *
 * Telefone só no formato brasileiro plausível, com borda de não-dígito dos dois lados:
 *   · `(DD) NNNNN-NNNN` / `(DD) NNNN-NNNN` (hífen opcional);
 *   · `DD 9NNNN-NNNN` (DDD sem parênteses só para celular, que começa com 9);
 *   · sem DDD, só com hífen e primeiro dígito 9 (celular) ou 3-5 (fixo).
 * Fica de fora o que se confunde com ano, intervalo e número de lei ("2023-2024", "13105-2015",
 * "1999-2000"): fixo sem DDD começando em 2 não é mascarado — é o preço de não apagar datas.
 *
 * CPF e CNPJ SEM pontuação ("CPF 12345678909") também são mascarados, mas só quando a sequência
 * isolada de 11 (CPF) ou 14 (CNPJ) dígitos passa no dígito verificador: um número solto qualquer
 * (protocolo, telefone sem formatação, CNJ sem pontuação de 20 dígitos) fica intacto. A borda de
 * não-dígito dos dois lados garante que um pedaço de uma sequência maior nunca é mascarado.
 * Sequências de um dígito só repetido (00000000000) passam no cálculo, mas não são documento:
 * ficam de fora.
 *
 * Pontuação PARCIAL ou com espaços (IA-002b) também é mascarada, sempre com o DV conferido:
 *   · CPF `123456789-09`, `123.456.789 09`, `123 456 789 09` — cada separador é opcional, nos
 *     lugares do formato oficial (`.`/espaço entre os grupos, `-`/`.`/espaço antes do DV);
 *   · CNPJ numérico com espaço no lugar da pontuação (`11 222 333 0001 81`);
 *   · CNPJ ALFANUMÉRICO (IN RFB 2.229/2024; emissão a partir de julho de 2026): 12 caracteres `[0-9A-Z]`
 *     + 2 DV numéricos, com ou sem a pontuação oficial (`12.ABC.345/01DE-35`, `12ABC34501DE35`).
 *     DV pelo manual do SERPRO/Receita "Cálculo dos dígitos verificadores de CNPJ alfanumérico":
 *     valor de cada caractere = código ASCII − 48 (`0`=0 … `9`=9, `A`=17 … `Z`=42), mesmos pesos e
 *     mesmo módulo 11 do CNPJ numérico — que é o caso particular só com dígitos. Só letras
 *     MAIÚSCULAS, como na tabela oficial; espaço como separador só vale para o numérico (no
 *     alfanumérico, palavras em caixa alta separadas por espaço casariam com o formato).
 * Nas variantes com espaço a borda é mais rígida: nem dígito colado, nem dígito separado por um
 * único `.`, `-` ou espaço — para não pescar um documento no meio de uma lista de números.
 *
 * ESPAÇO inclui o U+00A0 (NBSP, IA-033): o `&nbsp;` do HTML do DJEN vira NBSP no
 * `html_entity_decode`, e "123&nbsp;456&nbsp;789&nbsp;09" é o mesmo CPF de "123 456 789 09". Em
 * todo lugar onde as variantes aceitam espaço — separador e borda — aceitam o NBSP. O NBSP NÃO é
 * normalizado para espaço no resto do texto: o mascarador só troca o documento pelo marcador e
 * devolve todo o resto byte a byte. (Os montadores já passam o texto pelo
 * {@see NeutralizadorDeConteudo}, cujo `\s+` com `/u` casa o NBSP e o vira espaço antes da máscara;
 * a regra aqui é para o mascarador não depender de quem o chama.) O telefone usa `\s`, que com
 * `/u` (UCP) já casa o NBSP.
 */
final class MascaradorDeDadosPessoais
{
    private const CNJ = '/\b\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}\b/u';

    /** Formatos com pontuação (como o Designer): mascarados sem conferir o DV. */
    private const CNPJ = '/(?<!\d)\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}(?!\d)/u';
    private const CPF = '/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/u';
    /**
     * Sequência isolada de dígitos ASCII, sem pontuação; o DV decide se é CPF/CNPJ. `[0-9]` e não
     * `\d`: com `/u` o `\d` casa dígito Unicode, e o cálculo do DV lê byte a byte.
     */
    private const CNPJ_SEM_PONTUACAO = '/(?<![0-9])[0-9]{14}(?![0-9])/u';
    private const CPF_SEM_PONTUACAO = '/(?<![0-9])[0-9]{11}(?![0-9])/u';

    /**
     * Pontuação parcial ou espaços (IA-002b); o DV decide. A borda dupla — `(?<![0-9])` e
     * `(?<![0-9][. -])`, idem à direita — impede casar um pedaço de "100 123 456 789 09 5".
     */
    private const CPF_PONTUACAO_PARCIAL = '/(?<![0-9])(?<![0-9][. \x{00A0}-])'
        . '[0-9]{3}[. \x{00A0}]?[0-9]{3}[. \x{00A0}]?[0-9]{3}[-. \x{00A0}]?[0-9]{2}'
        . '(?![0-9])(?![. \x{00A0}-][0-9])/u';
    private const CNPJ_NUMERICO_COM_ESPACOS = '/(?<![0-9])(?<![0-9][. \x{00A0}\/-])'
        . '[0-9]{2}[. \x{00A0}]?[0-9]{3}[. \x{00A0}]?[0-9]{3}[\/ \x{00A0}]?[0-9]{4}[- \x{00A0}]?[0-9]{2}'
        . '(?![0-9])(?![. \x{00A0}\/-][0-9])/u';
    /** CNPJ alfanumérico (e o numérico com pontuação parcial): 12 `[0-9A-Z]` + 2 DV, pontuação oficial opcional. */
    private const CNPJ_ALFANUMERICO = '/(?<![\p{L}\p{N}])'
        . '[0-9A-Z]{2}\.?[0-9A-Z]{3}\.?[0-9A-Z]{3}\/?[0-9A-Z]{4}-?[0-9]{2}'
        . '(?![\p{L}\p{N}])/u';
    private const EMAIL = '/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/u';

    private const TELEFONE = '/(?<![\d-])(?:'
        . '\(\d{2}\)\s?(?:9\d{4}|\d{4})-?\d{4}'   // (61) 99999-1234 · (61) 3333-4444 · (61)33334444
        . '|\d{2}\s9\d{4}-?\d{4}'                  // 61 99999-1234
        . '|(?:9\d{4}|[3-5]\d{3})-\d{4}'           // 99999-1234 · 3333-4444 (sem DDD exige hífen)
        . ')(?![\d-])/u';

    private const MARCADOR_CNJ = "\u{E000}CNJ%d\u{E001}";

    public function mascarar(string $texto): string
    {
        if ($texto === '') {
            return '';
        }

        // 1. Protege os números CNJ.
        $protegidos = [];
        $texto = (string) preg_replace_callback(
            self::CNJ,
            static function (array $m) use (&$protegidos): string {
                $protegidos[] = $m[0];

                return sprintf(self::MARCADOR_CNJ, count($protegidos) - 1);
            },
            $texto,
        );

        // 2. Mascara — e-mail e CNPJ antes do CPF/telefone, que são padrões mais curtos. Os sem
        //    pontuação vêm depois dos formatados e só valem com o DV certo.
        $texto = (string) preg_replace(self::EMAIL, '[EMAIL]', $texto);
        $texto = (string) preg_replace(self::CNPJ, '[CNPJ]', $texto);
        $texto = (string) preg_replace(self::CPF, '[CPF]', $texto);
        $texto = (string) preg_replace_callback(
            self::CNPJ_SEM_PONTUACAO,
            static fn (array $m): string => self::cnpjValido($m[0]) ? '[CNPJ]' : $m[0],
            $texto,
        );
        // Pontuação parcial/espaços e CNPJ alfanumérico (IA-002b): CNPJ antes do CPF, que é mais
        // curto; só vale com o DV certo, conferido sobre os caracteres sem separador.
        foreach ([self::CNPJ_ALFANUMERICO, self::CNPJ_NUMERICO_COM_ESPACOS] as $padrao) {
            $texto = (string) preg_replace_callback(
                $padrao,
                static fn (array $m): string => self::cnpjValido(self::semSeparadores($m[0])) ? '[CNPJ]' : $m[0],
                $texto,
            );
        }
        $texto = (string) preg_replace_callback(
            self::CPF_SEM_PONTUACAO,
            static fn (array $m): string => self::cpfValido($m[0]) ? '[CPF]' : $m[0],
            $texto,
        );
        $texto = (string) preg_replace_callback(
            self::CPF_PONTUACAO_PARCIAL,
            static fn (array $m): string => self::cpfValido(self::semSeparadores($m[0])) ? '[CPF]' : $m[0],
            $texto,
        );
        $texto = (string) preg_replace(self::TELEFONE, '[TEL]', $texto);

        // 3. Restaura os CNJ.
        foreach ($protegidos as $indice => $cnj) {
            $texto = str_replace(sprintf(self::MARCADOR_CNJ, $indice), $cnj, $texto);
        }

        return $texto;
    }

    /** DV do CPF (módulo 11, pesos 10..2 e 11..2). Recebe exatamente 11 dígitos. */
    private static function cpfValido(string $digitos): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $digitos) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao <= 10; ++$posicao) {
            $soma = 0;
            for ($i = 0; $i < $posicao; ++$i) {
                $soma += (int) $digitos[$i] * ($posicao + 1 - $i);
            }
            $resto = ($soma * 10) % 11;
            if (($resto === 10 ? 0 : $resto) !== (int) $digitos[$posicao]) {
                return false;
            }
        }

        return true;
    }

    /**
     * DV do CNPJ (módulo 11, pesos 5..2,9..2 e 6..2,9..2). Recebe exatamente 14 caracteres: os 12
     * primeiros `[0-9A-Z]`, os 2 últimos dígitos. Valor de cada caractere = ASCII − 48 (manual do
     * SERPRO/Receita para o CNPJ alfanumérico); para dígito dá o próprio dígito, então o CNPJ
     * numérico é o mesmo cálculo de antes.
     */
    private static function cnpjValido(string $caracteres): bool
    {
        if (preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $caracteres) !== 1 || preg_match('/^(\d)\1{13}$/', $caracteres) === 1) {
            return false;
        }

        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $posicao => $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (ord($caracteres[$i]) - 48) * $peso;
            }
            $resto = $soma % 11;
            if (($resto < 2 ? 0 : 11 - $resto) !== (int) $caracteres[$posicao]) {
                return false;
            }
        }

        return true;
    }

    /** Tira os separadores aceitos pelos formatos parciais (ponto, barra, hífen, espaço, NBSP). */
    private static function semSeparadores(string $documento): string
    {
        return str_replace(['.', '/', '-', ' ', "\u{00A0}"], '', $documento);
    }
}
