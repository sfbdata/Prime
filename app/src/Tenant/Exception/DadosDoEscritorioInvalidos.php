<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/** Dado recusado na tela Meu Escritório. A mensagem é a frase do desenho, escrita para quem está na tela (422). */
final class DadosDoEscritorioInvalidos extends \DomainException
{
    public function __construct(string $mensagem, public readonly string $campo = '')
    {
        parent::__construct($mensagem);
    }
}
