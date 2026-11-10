<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

/** O bloco `pg`: a página `numero` do PDF enviado, sozinha numa página da folha (op `img`). */
final readonly class PaginaDoPdfEnviado implements ElementoDaPagina
{
    public function __construct(public int $numero)
    {
    }
}
