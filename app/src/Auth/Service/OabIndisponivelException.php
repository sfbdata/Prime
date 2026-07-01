<?php

declare(strict_types=1);

namespace App\Auth\Service;

/**
 * O web service de OAB não pôde responder (sem backend configurado, timeout, erro de
 * transporte ou XML inválido). O ValidadorOab trata isso como fail-open → `nao_verificada`.
 */
final class OabIndisponivelException extends \RuntimeException
{
}
