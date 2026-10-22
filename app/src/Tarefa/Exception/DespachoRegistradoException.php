<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada pela entidade quando alguém tenta mudar o texto, a natureza ou o prazo de um despacho já registrado (com
 * código, D1). É `\DomainException` (portanto `\LogicException`): o desfazer da auditoria a recusa sem quebrar.
 */
final class DespachoRegistradoException extends \DomainException
{
    public static function imutavel(): self
    {
        return new self('Despacho registrado não pode ser editado.');
    }
}
