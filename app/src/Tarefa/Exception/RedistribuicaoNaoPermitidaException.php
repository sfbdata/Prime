<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/** Quem pede a redistribuição não é o Master nem um primário ativo do escritório (403). A mensagem é a que o usuário lê. */
final class RedistribuicaoNaoPermitidaException extends \DomainException
{
}
