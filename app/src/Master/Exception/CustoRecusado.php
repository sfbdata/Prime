<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa de uma ação da seção Custos (spec master-custos): dado inválido, lançamento que não existe ou mês anterior
 * sem lançamentos. O controller devolve 422 com a mensagem como está.
 */
final class CustoRecusado extends \DomainException
{
}
