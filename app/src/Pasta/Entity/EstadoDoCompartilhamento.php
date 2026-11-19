<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Estados do compartilhamento de uma pasta (spec acesso-restrito-e-compartilhar §4.4). O interno nasce `Aceita`;
 * `Pendente` e `Recusada` são da outra conta (fatia 8). `Encerrada` é final.
 */
enum EstadoDoCompartilhamento: string
{
    case Pendente  = 'pendente';
    case Aceita    = 'aceita';
    case Recusada  = 'recusada';
    case Encerrada = 'encerrada';

    /** Ocupa a vaga do par pasta + destino (o índice único parcial). */
    public function ocupaAVaga(): bool
    {
        return $this === self::Pendente || $this === self::Aceita;
    }
}
