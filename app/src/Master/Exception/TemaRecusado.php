<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao configurar os temas comemorativos (spec docs/specs/temas-comemorativos.md): modo inválido ou tema
 * fora do catálogo. O controller devolve 422 com a mensagem como está.
 */
final class TemaRecusado extends \DomainException
{
}
