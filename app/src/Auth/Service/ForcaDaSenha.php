<?php

declare(strict_types=1);

namespace App\Auth\Service;

/**
 * A régua de senha do desenho 10, igual no servidor e na tela (o JS da página repete a mesma conta):
 * um ponto por ter 8 ou mais caracteres, por misturar maiúsculas e minúsculas, por ter número e por ter símbolo.
 * Aceita com 8 ou mais caracteres e pelo menos 2 pontos.
 */
final class ForcaDaSenha
{
    public const MINIMO = 8;
    public const ERRO = 'Use pelo menos 8 caracteres, com letras e números.';

    /**
     * Acima disso o hasher do Symfony recusa a senha com exceção (proteção contra DoS no hash). Em BYTES, como o
     * hasher conta: em caracteres, acento passaria e daria 500. Quem passa disso recebe a mesma mensagem da fraca.
     */
    public const MAXIMO_EM_BYTES = 4096;

    public static function pontos(string $senha): int
    {
        if ($senha === '') {
            return 0;
        }

        return (int) (mb_strlen($senha) >= self::MINIMO)
            + (int) (preg_match('/[A-Z]/', $senha) === 1 && preg_match('/[a-z]/', $senha) === 1)
            + (int) (preg_match('/\d/', $senha) === 1)
            + (int) (preg_match('/[^A-Za-z0-9_]/', $senha) === 1);
    }

    public static function aceita(string $senha): bool
    {
        return mb_strlen($senha) >= self::MINIMO && self::pontos($senha) >= 2;
    }
}
