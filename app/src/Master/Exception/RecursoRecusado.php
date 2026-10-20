<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao ligar ou desligar uma tela ou função (spec perfis-1.2-telas-e-recursos, R1 e R3): chave
 * fora do catálogo ou tela essencial. O controller devolve 422 com a mensagem como está.
 */
final class RecursoRecusado extends \DomainException
{
}
