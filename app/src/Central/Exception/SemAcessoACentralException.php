<?php

declare(strict_types=1);

namespace App\Central\Exception;

/** Sem vínculo ATIVO com o escritório da sessão: não usa a Central. O controller responde 403. */
final class SemAcessoACentralException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Sem acesso à Central neste escritório.');
    }
}
