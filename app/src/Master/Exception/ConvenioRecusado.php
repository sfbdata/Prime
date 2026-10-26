<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao mudar o convênio OAB de um estado (desenho 05 Perfis 1.2 VS 25.10.26): estado desconhecido, modo inválido,
 * valor ou data fora da regra. O controller devolve 422 com a mensagem.
 */
final class ConvenioRecusado extends \DomainException
{
}
