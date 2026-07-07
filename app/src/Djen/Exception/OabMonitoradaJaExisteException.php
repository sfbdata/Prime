<?php

declare(strict_types=1);

namespace App\Djen\Exception;

/**
 * Tentativa de cadastrar uma OAB (número+UF) que o escritório já monitora. É erro de entrada do
 * usuário (não de sistema): o controller a traduz em mensagem amigável no formulário.
 */
final class OabMonitoradaJaExisteException extends \DomainException
{
    public function __construct(string $numero, string $uf)
    {
        parent::__construct(sprintf('A OAB %s/%s já está sendo monitorada neste escritório.', $numero, $uf));
    }
}
