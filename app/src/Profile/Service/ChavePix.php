<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Shared\Service\DigitoVerificador;

/**
 * Reconhece uma chave PIX pelo formato (spec docs/specs/meu-perfil-pix-recompensas.md): CPF e CNPJ pelos dígitos
 * verificadores, e-mail, celular (com ou sem +55) e chave aleatória (UUID). Não consulta o banco: se a chave existe no
 * PIX, só o pagamento mostra.
 */
final class ChavePix
{
    public const TAMANHO_MAXIMO = 140;

    public static function valida(string $chave): bool
    {
        $chave = trim($chave);
        if ($chave === '' || mb_strlen($chave) > self::TAMANHO_MAXIMO) {
            return false;
        }

        return DigitoVerificador::cpfValido($chave) && self::soNumerosEPontuacao($chave)
            || DigitoVerificador::cnpjValido($chave) && self::soNumerosEPontuacao($chave)
            || filter_var($chave, \FILTER_VALIDATE_EMAIL) !== false
            || self::celular($chave)
            || self::aleatoria($chave);
    }

    private static function soNumerosEPontuacao(string $chave): bool
    {
        return preg_match('/^[\d.\-\/]+$/', $chave) === 1;
    }

    /** DDD de 11 a 99 e o nove na frente: o telefone fixo não é chave PIX. */
    private static function celular(string $chave): bool
    {
        if (preg_match('/^(?:\+\s*55)?[\s()\-]*(\d{2})[\s()\-]*(9\d{4})[\s\-]*(\d{4})$/', $chave, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 11;
    }

    private static function aleatoria(string $chave): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $chave) === 1;
    }
}
