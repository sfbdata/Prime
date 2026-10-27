<?php

declare(strict_types=1);

namespace App\ServiceDesk\Exception;

/** Chamado inexistente OU de outro escritório: as duas respostas são a mesma (o controller devolve 404). */
final class ChamadoNaoEncontrado extends \DomainException
{
}
