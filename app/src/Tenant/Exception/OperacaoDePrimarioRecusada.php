<?php
declare(strict_types=1);

namespace App\Tenant\Exception;

/**
 * Recusa de regra de negócio sobre os primários do escritório (promover, remover, rebaixar).
 * A mensagem é escrita para quem está na tela: o controller a mostra como está.
 */
final class OperacaoDePrimarioRecusada extends \DomainException
{
}
