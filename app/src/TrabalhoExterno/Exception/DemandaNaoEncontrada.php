<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Exception;

/**
 * Não existe OU não é visível para quem pede: as duas respostas são iguais (404) de propósito,
 * para não confirmar a existência de demanda de outro escritório.
 */
final class DemandaNaoEncontrada extends \DomainException
{
    public function __construct(string $mensagem = 'Demanda não encontrada.')
    {
        parent::__construct($mensagem);
    }
}
