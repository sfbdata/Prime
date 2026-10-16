<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Dígito verificador de CPF e CNPJ (desenho 1.2.3, `cpfValido`). Aceita com ou sem máscara; sequência repetida
 * ("111.111.111-11") é inválida. O CNPJ repete a conta do {@see \App\Validator\CnpjValidator}.
 */
final class DigitoVerificador
{
    public static function digitos(?string $valor): string
    {
        return (string) preg_replace('/\D/', '', (string) $valor);
    }

    public static function cpfValido(?string $cpf): bool
    {
        $d = self::digitos($cpf);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d) === 1) {
            return false;
        }
        foreach ([9, 10] as $n) {
            $soma = 0;
            for ($i = 0; $i < $n; ++$i) {
                $soma += (int) $d[$i] * ($n + 1 - $i);
            }
            $dv = ($soma * 10) % 11 % 10;
            if ((int) $d[$n] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjValido(?string $cnpj): bool
    {
        $d = self::digitos($cnpj);
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d) === 1) {
            return false;
        }
        foreach ([12, 13] as $n) {
            $soma = 0;
            $peso = $n - 7;
            for ($i = 0; $i < $n; ++$i) {
                $soma += (int) $d[$i] * $peso--;
                if ($peso < 2) {
                    $peso = 9;
                }
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
            if ((int) $d[$n] !== $dv) {
                return false;
            }
        }

        return true;
    }
}
