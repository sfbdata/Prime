<?php

declare(strict_types=1);

namespace App\Processo\Service;

/**
 * O estrago que o `DatajudProcessoMapper::fixOrgaoJulgador` antigo gravava: texto que já era UTF-8 lido de novo
 * como ISO-8859-1 ("1ª Vara Cível" virou "1Âª VARA CÃ­VEL"). Cada letra acentuada vira "Ã" ou "Â" seguido de
 * um caractere entre U+0080 e U+00BF (alguns invisíveis, como os de controle de "Ú" e "Ã" maiúsculos).
 *
 * A reversão é exata: volta o texto para ISO-8859-1 e lê os bytes como UTF-8. Uma exceção conhecida: o caixa
 * alta da entidade transformou o "µ" de "õ" (bytes C3 B5) no "Μ" grego, e "SUCESSÕES" ficou "SUCESSÃΜES"; o
 * "Μ" depois de "Ã" volta para "µ" antes. Quando o resultado não sai limpo (bytes que não formam UTF-8, ou o
 * padrão continua), a reversão devolve nulo e o texto fica como está.
 */
final class AcentuacaoEstragada
{
    private const PADRAO = '/[ÃÂ][\x{80}-\x{BF}\x{39C}]/u';

    public function temEstrago(string $texto): bool
    {
        return preg_match(self::PADRAO, $texto) === 1;
    }

    /** O texto revertido, em maiúsculas como a entidade grava; nulo quando não há estrago ou ele não sai limpo. */
    public function reverter(string $texto): ?string
    {
        if (!$this->temEstrago($texto)) {
            return null;
        }

        $semMuGrego = str_replace('ÃΜ', 'Ãµ', $texto);
        if (preg_match('/[^\x{00}-\x{FF}]/u', $semMuGrego) === 1) {
            return null;
        }

        $bytes = mb_convert_encoding($semMuGrego, 'ISO-8859-1', 'UTF-8');
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        $revertido = mb_strtoupper($bytes);

        return $this->temEstrago($revertido) ? null : $revertido;
    }
}
