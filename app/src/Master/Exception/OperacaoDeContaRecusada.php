<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa de regra de negócio ao suspender ou reativar conta pelo Master (spec perfis-1.2-contas C2,
 * C3 e C5). A mensagem é escrita para quem está na tela: o controller a devolve como está.
 */
final class OperacaoDeContaRecusada extends \DomainException
{
}
