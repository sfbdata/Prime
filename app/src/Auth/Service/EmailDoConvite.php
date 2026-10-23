<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * Regra do e-mail de quem é convidado (achado de auditoria interna F3): a mesma do cadastro público
 * (`CriarContaEEscritorioUseCase::emailValido`), a constraint Email no modo estrito e o tamanho da coluna.
 */
final class EmailDoConvite
{
    public const ERRO = 'Informe um e-mail válido.';

    private const TAMANHO_MAXIMO = 180;

    /** Devolve o e-mail normalizado (sem espaços, em minúsculas) ou recusa com a mensagem do fluxo. */
    public static function normalizar(string $email): string
    {
        $email = strtolower(trim($email));

        if (mb_strlen($email) > self::TAMANHO_MAXIMO || count(Validation::createValidator()->validate($email, [
            new Assert\NotBlank(),
            new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT),
        ])) > 0) {
            throw new \DomainException(self::ERRO);
        }

        return $email;
    }
}
