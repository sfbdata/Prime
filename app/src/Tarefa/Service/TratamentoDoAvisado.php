<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

/**
 * Como o avisado aparece na caixa da esteira (desenho 04 de 22/10, `trat()` do dc L155-160): "Primeiro Último" com
 * as iniciais maiúsculas; advogado (função com "advog", "sóci", "dr" ou "dra", ou com OAB) ganha "Dr." ou "Dra.".
 *
 * O sistema não guarda o gênero, então vale a regra do desenho para o primeiro nome: termina em "a", "e", "ly" ou
 * "ny" é feminino, menos os nomes masculinos da lista do desenho (José, André, Felipe...).
 */
final class TratamentoDoAvisado
{
    private const MASCULINOS = '/^(jos[eé]|andr[eé]|felipe|jorge|henrique|dante|vicente|guilherme|alexandre|wellington|luca|joshua)$/iu';

    public static function de(string $nome, ?string $funcao, bool $temOab): string
    {
        $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($partes === []) {
            return '';
        }
        $curto = self::maiuscula($partes[0]) . (count($partes) > 1 ? ' ' . self::maiuscula($partes[count($partes) - 1]) : '');
        $advogado = $temOab || preg_match('/advog|s[oó]ci|dr\.?|dra\.?/u', mb_strtolower((string) $funcao)) === 1;
        if (!$advogado) {
            return $curto;
        }

        return (self::feminino($partes[0]) ? 'Dra. ' : 'Dr. ') . $curto;
    }

    private static function feminino(string $primeiro): bool
    {
        return preg_match('/(a|e|ly|ny)$/iu', $primeiro) === 1 && preg_match(self::MASCULINOS, $primeiro) !== 1;
    }

    private static function maiuscula(string $palavra): string
    {
        return mb_strtoupper(mb_substr($palavra, 0, 1)) . mb_strtolower(mb_substr($palavra, 1));
    }
}
