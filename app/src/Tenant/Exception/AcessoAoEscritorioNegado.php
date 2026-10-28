<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/**
 * Quem tentou mexer nos dados, na logo ou na exclusão do escritório não é o primário dele nem Master (D1 da spec
 * meu-escritorio.md). O controller a vira 403 e grava a tentativa no audit_log.
 */
final class AcessoAoEscritorioNegado extends \DomainException
{
    public const MENSAGEM = 'Só o primário do escritório ou o Master pode fazer isto.';

    public function __construct(string $mensagem = self::MENSAGEM)
    {
        parent::__construct($mensagem);
    }
}
