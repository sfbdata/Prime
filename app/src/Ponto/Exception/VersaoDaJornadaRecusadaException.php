<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Regra das versões da jornada recusou a gravação (data repetida, única versão). A mensagem é escrita para quem
 * administra o ponto: o controller a devolve com 422.
 */
final class VersaoDaJornadaRecusadaException extends \DomainException
{
}
