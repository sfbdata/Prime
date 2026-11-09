<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * O e-mail como entra na trilha (`dados.para`): a primeira letra, três asteriscos e o domínio (`a***@exemplo.com`).
 * A trilha prova para onde foi sem expor o endereço inteiro.
 */
final class MascaraDeEmail
{
    private function __construct()
    {
    }

    public static function mascarar(string $email): string
    {
        $arroba = mb_strrpos($email, '@');
        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . mb_substr($email, $arroba);
    }
}
