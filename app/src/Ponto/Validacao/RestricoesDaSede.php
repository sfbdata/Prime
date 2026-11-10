<?php

declare(strict_types=1);

namespace App\Ponto\Validacao;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\DivisibleBy;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Regra única da cerca de uma sede: latitude, longitude e raio, com as mensagens que a página "Sedes do ponto" já
 * mostrava. Duas portas gravam sede (a página antiga pelo `SedeType` e a aba Configurações da /ponto pelo
 * `SalvarSedeDoPontoUseCase`, spec `docs/specs/ponto-desenho-0811.md` E6); com a regra num lugar só, elas não têm
 * como divergir.
 */
final class RestricoesDaSede
{
    public const RAIO_MINIMO = 10;
    public const RAIO_MAXIMO = 500;

    public const MENSAGEM_RAIO_NAO_NUMERO = 'Raio permitido deve ser um número.';

    /** @return list<Constraint> */
    public static function latitude(): array
    {
        return [
            new NotNull(message: 'Latitude é obrigatória.'),
            new Range(min: -90, max: 90, notInRangeMessage: 'Latitude deve estar entre -90 e 90.', invalidMessage: 'Latitude deve ser um número.'),
        ];
    }

    /** @return list<Constraint> */
    public static function longitude(): array
    {
        return [
            new NotNull(message: 'Longitude é obrigatória.'),
            new Range(min: -180, max: 180, notInRangeMessage: 'Longitude deve estar entre -180 e 180.', invalidMessage: 'Longitude deve ser um número.'),
        ];
    }

    /** @return list<Constraint> */
    public static function raio(): array
    {
        return [
            new Range(min: self::RAIO_MINIMO, max: self::RAIO_MAXIMO, notInRangeMessage: 'Raio permitido deve estar entre 10 e 500 metros.'),
            // A coluna é inteira: sem isto, 10.5 virava 10 em silêncio (conversão implícita do setter).
            new DivisibleBy(value: 1, message: 'Raio permitido deve ser um número inteiro de metros.'),
        ];
    }
}
