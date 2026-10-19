<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa de regra de negócio ao promover ou remover um Master secundário (spec perfis-1.2-masters §4.2).
 * A mensagem é escrita para quem está na tela: o controller a mostra como está, no flash.
 */
final class OperacaoDeMasterRecusada extends \DomainException
{
}
