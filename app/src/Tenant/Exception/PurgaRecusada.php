<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/**
 * A purga de UM escritório foi recusada por uma guarda de elegibilidade (spec perfis-1.2-contas):
 * conta ativa, fora da quarentena, ainda na carência, recuperada depois de entrar na lista do job, com
 * o criador trocado ou a conta do Master. Nada foi apagado; o comando segue para os demais.
 */
final class PurgaRecusada extends \LogicException
{
}
