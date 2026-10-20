<?php

declare(strict_types=1);

namespace App\Master\Exception;

/** O id da rota não é de nenhum escritório (suspender e reativar conta pelo Master): o controller responde 404. */
final class ContaNaoEncontrada extends \DomainException
{
}
