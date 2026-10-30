<?php

declare(strict_types=1);

namespace App\ChatIa\Enum;

/**
 * O que a proposta de cadastro quer criar. Os valores são contrato com a tela e com o banco. A ferramenta que o modelo
 * chama (`propor_cliente`, `propor_pasta`) mapeia para ele; "cliente junto da pasta" é uma proposta de pasta.
 */
enum TipoDaProposta: string
{
    case Cliente = 'cliente';
    case Pasta = 'pasta';

    public const FERRAMENTA_CLIENTE = 'propor_cliente';
    public const FERRAMENTA_PASTA = 'propor_pasta';

    public static function daFerramenta(string $nome): ?self
    {
        return match ($nome) {
            self::FERRAMENTA_CLIENTE => self::Cliente,
            self::FERRAMENTA_PASTA => self::Pasta,
            default => null,
        };
    }
}
