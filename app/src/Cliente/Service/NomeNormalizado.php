<?php

declare(strict_types=1);

namespace App\Cliente\Service;

/**
 * Nome comparável (Inteligência cadastral, F-A): sem acento, sem diferença de caixa, pontuação vira espaço e os espaços
 * ficam únicos. "José  da Silva." e "JOSE DA SILVA" são o mesmo nome. O `ClienteRepository` repete a regra no SQL
 * (`unaccent` + `lower` + `regexp_replace`) para achar os homônimos no banco.
 */
final class NomeNormalizado
{
    private function __construct()
    {
    }

    public static function de(string $nome): string
    {
        $decomposto = \Normalizer::normalize($nome, \Normalizer::FORM_D);
        $semAcento = (string) preg_replace('/\p{Mn}+/u', '', \is_string($decomposto) ? $decomposto : $nome);
        $comparavel = (string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($semAcento));

        return trim($comparavel);
    }

    public static function iguais(string $a, string $b): bool
    {
        return self::de($a) === self::de($b);
    }
}
