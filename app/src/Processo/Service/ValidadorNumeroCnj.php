<?php

declare(strict_types=1);

namespace App\Processo\Service;

/**
 * Valida o dígito verificador do número unificado (Res. CNJ 65/2008).
 *
 * O número segue o formato NNNNNNN-DD.AAAA.J.TR.OOOO; o par DD é calculado de modo que
 * NNNNNNN.AAAA.J.TR.OOOO.DD deixe resto 1 na divisão por 97 (módulo 97 base 10).
 *
 * Serve para SEPARAR duas situações que a busca no CNJ confunde: número mal digitado versus
 * número correto que a base pública ainda não publicou (ela tem semanas de atraso e omite
 * processos em segredo de justiça). Sem isso o sistema manda o usuário "conferir o número"
 * mesmo quando o número está certo.
 *
 * NÃO valida se o tribunal existe (isso é do TribunalCnjResolver) nem se o processo existe.
 */
final class ValidadorNumeroCnj
{
    /**
     * true quando o número tem 20 dígitos e o DV confere; false em qualquer outro caso
     * (inclusive fora do padrão CNJ, onde não há DV a conferir).
     *
     * Aceita o número mascarado ou puro — a pontuação é descartada.
     */
    public function digitoVerificadorConfere(string $numeroProcesso): bool
    {
        $digitos = preg_replace('/\D+/', '', $numeroProcesso) ?? '';

        // NNNNNNN(7) DD(2) AAAA(4) J(1) TR(2) OOOO(4) = 20 dígitos.
        if (!preg_match('/^(\d{7})(\d{2})(\d{11})$/', $digitos, $partes)) {
            return false;
        }

        [, $sequencial, $dv, $restante] = $partes;

        return $this->restoModulo97($sequencial . $restante . $dv) === 1;
    }

    /**
     * Resto da divisão por 97 calculado dígito a dígito.
     *
     * Obrigatoriamente incremental: o número concatenado tem 20 dígitos e passa de
     * 9,2×10^18 (limite do int de 64 bits), então (int) o converteria em float e o resto
     * sairia errado — sem erro nenhum, só com a resposta trocada. Aqui o maior valor
     * intermediário é 96×10+9 = 969.
     */
    private function restoModulo97(string $digitos): int
    {
        $resto = 0;
        foreach (str_split($digitos) as $digito) {
            $resto = ($resto * 10 + (int) $digito) % 97;
        }

        return $resto;
    }
}
