<?php

declare(strict_types=1);

namespace App\Auth\Service;

/**
 * Regra única de senha e nome para quem cria a conta aceitando um convite (de plataforma ou de escritório).
 * O `required` do HTML não protege um POST direto, então o servidor confere aqui o mesmo que os DTOs de
 * senha do Auth conferem (Assert\Length(min: 8, max: 4096)).
 */
final class DadosDaContaDoConvite
{
    public const SENHA_MINIMA = 8;

    /** Limite do hasher do Symfony, em BYTES: acima disso ele lança exceção e a tela daria erro 500. */
    public const SENHA_MAXIMA_BYTES = 4096;

    public static function exigirSenha(string $senha): void
    {
        if (mb_strlen($senha) < self::SENHA_MINIMA) {
            throw new \InvalidArgumentException('A senha deve ter ao menos 8 caracteres.');
        }

        if (strlen($senha) > self::SENHA_MAXIMA_BYTES) {
            throw new \InvalidArgumentException('A senha é longa demais.');
        }
    }

    /** O nome digitado (sem espaços nas pontas) ou, em branco, o nome do convite; nenhum dos dois é recusado. */
    public static function nome(string $digitado, ?string $doConvite): string
    {
        $nome = trim($digitado) !== '' ? trim($digitado) : trim((string) $doConvite);
        if ($nome === '') {
            throw new \InvalidArgumentException('Nome completo é obrigatório.');
        }

        return $nome;
    }
}
