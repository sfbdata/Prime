<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa de uma ação da seção Desempenho (spec master-desempenho): incidente que não existe ou que já foi
 * resolvido. O controller devolve 422 com a mensagem como está.
 */
final class DesempenhoRecusado extends \DomainException
{
}
