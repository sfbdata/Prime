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
 */
final class MascaradorDeDadosPessoais
{
    private const CNJ = '/\b\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}\b/u';

    /** Só os formatos com pontuação (como o Designer): dígito solto não é PII identificável. */
    private const CNPJ = '/(?<!\d)\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}(?!\d)/u';
    private const CPF = '/(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/u';
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

        // 2. Mascara — e-mail e CNPJ antes do CPF/telefone, que são padrões mais curtos.
        $texto = (string) preg_replace(self::EMAIL, '[EMAIL]', $texto);
        $texto = (string) preg_replace(self::CNPJ, '[CNPJ]', $texto);
        $texto = (string) preg_replace(self::CPF, '[CPF]', $texto);
        $texto = (string) preg_replace(self::TELEFONE, '[TEL]', $texto);

        // 3. Restaura os CNJ.
        foreach ($protegidos as $indice => $cnj) {
            $texto = str_replace(sprintf(self::MARCADOR_CNJ, $indice), $cnj, $texto);
        }

        return $texto;
    }
}
