<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * O link `/votar/{token}` não leva a nenhuma cédula: token inexistente (ou de link trocado por "Gerar novo link") ou
 * escritório da assembleia sem o Voto liberado pelo Master. A página responde 404 e consome o limitador
 * `voto_cedula_invalido`.
 */
final class CedulaNaoEncontradaException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Link inválido ou eleição indisponível.');
    }
}
