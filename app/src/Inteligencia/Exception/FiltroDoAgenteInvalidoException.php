<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * O filtro que o Agente de Pastas mandou não tem tradução segura para a busca da Lista — situação que
 * não existe, data torta, combinação que o repositório não sabe fazer. O controller responde 422 com
 * o campo: o repositório IGNORARIA o valor e devolveria "todas as pastas", e o agente acharia que
 * filtrou.
 */
final class FiltroDoAgenteInvalidoException extends \DomainException
{
    public function __construct(
        public readonly string $campo,
        string $mensagem,
    ) {
        parent::__construct($mensagem);
    }
}
