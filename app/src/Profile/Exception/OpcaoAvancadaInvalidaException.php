<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/**
 * Fuso, formato de data ou de número fora da lista das "Opções avançadas" (spec sistema-em-ingles). A mensagem é a
 * chave do catálogo: o controller traduz no idioma de quem pediu e responde 422.
 */
final class OpcaoAvancadaInvalidaException extends \DomainException
{
    public const CHAVE = 'opcoes_avancadas.erro_opcao';

    public function __construct()
    {
        parent::__construct(self::CHAVE);
    }
}
