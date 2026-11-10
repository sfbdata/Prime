<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A pessoa não tem vínculo ativo no escritório da sessão (aba Equipe da /ponto). Para quem pede, ela não existe aqui:
 * o controller responde 404, como para uma pessoa que não existe.
 */
final class ForaDaEquipeException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Pessoa não encontrada na equipe deste escritório.');
    }
}
