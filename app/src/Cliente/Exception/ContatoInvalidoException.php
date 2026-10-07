<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * Valor de contato recusado (e-mail malformado, telefone sem DDD, e-mail
 * vazio, campo desconhecido). A mensagem é para o usuário — o controller a
 * devolve como está, com 422.
 */
final class ContatoInvalidoException extends \DomainException
{
}
