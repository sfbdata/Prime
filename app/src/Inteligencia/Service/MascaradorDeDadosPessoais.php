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
 */
final class MascaradorDeDadosPessoais
{
    private const CNJ = '/\b\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}\b/u';

    /** Só os formatos com pontuação (como o Designer): dígito solto não é PII identificável. */
    private const CNPJ = '/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/u';
    private const CPF = '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/u';
    private const EMAIL = '/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/u';

    /** (DD) 9XXXX-XXXX · DD 9XXXX XXXX · XXXX-XXXX — exige separador entre os blocos, como o Designer. */
    private const TELEFONE = '/(?:\(?\b\d{2}\)?[\s.-]?)?9?\d{4}[-\s]\d{4}\b/u';

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
