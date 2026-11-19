<?php

declare(strict_types=1);

namespace App\Blog\Message;

/**
 * Pede o envio, por e-mail, de um artigo que acabou de ir ao ar pela primeira vez (spec blog-artigos-por-email).
 * Vai para a fila `comercial` com atraso, para o site já estar no ar quando o e-mail chegar.
 */
final class EnviarArtigoPorEmail
{
    public function __construct(public readonly int $artigoId)
    {
    }
}
