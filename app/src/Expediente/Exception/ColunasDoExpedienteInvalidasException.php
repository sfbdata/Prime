<?php

declare(strict_types=1);

namespace App\Expediente\Exception;

/**
 * O ajuste de colunas da Lista que chegou não é um mapa {coluna: bool} das colunas que a tabela tem.
 * O controller responde 422 com a mensagem; nada é gravado.
 */
final class ColunasDoExpedienteInvalidasException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Formato das colunas inválido.');
    }
}
