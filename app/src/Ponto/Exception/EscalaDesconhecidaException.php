<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/** A escala pedida na aba Equipe não é uma das 4 do desenho (`CatalogoDeEscalas`). O controller responde 422. */
final class EscalaDesconhecidaException extends RecusaDoPonto
{
    public const RECUSA_ESCALA_DA_LISTA = 'Escolha uma das escalas da lista.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_ESCALA_DA_LISTA => 'ponto_servidor.equipe.escala_da_lista',
    ];

    public function __construct()
    {
        parent::__construct(self::RECUSA_ESCALA_DA_LISTA);
    }
}
