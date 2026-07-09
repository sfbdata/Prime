<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de encerrar um Caso de Cobrança cujo saldo exigível ainda não está resolvido (SPEC §17):
 * o encerramento é manual e só é permitido quando a situação financeira está resolvida (saldo
 * exigível zero). Saldo zero apenas INDICA "pronto para encerrar"; o gestor confirma — mas nunca se
 * encerra um caso com saldo em aberto.
 */
final class SaldoNaoResolvidoException extends \DomainException
{
    public function __construct(int $casoId, int $saldoExigivel)
    {
        parent::__construct(sprintf(
            'Caso de cobrança %d não pode ser encerrado: saldo exigível de %d centavos ainda em aberto.',
            $casoId,
            $saldoExigivel,
        ));
    }
}
