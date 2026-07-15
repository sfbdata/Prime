<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de editar uma obrigação deixando seu valor exigível (valor original + encargos) ABAIXO do
 * que já foi pago/alocado nela. Isso criaria uma inconsistência silenciosa (a obrigação valeria menos do
 * que já recebeu). A correção de cadastro só pode reduzir o valor até o total já alocado, não além.
 */
final class ValorAbaixoDoAlocadoException extends \DomainException
{
    public function __construct(int $obrigacaoId, int $novoValorExigivel, int $totalAlocado)
    {
        parent::__construct(sprintf(
            'O valor exigível da obrigação %d (%d centavos) ficaria abaixo do total já pago/alocado (%d centavos).',
            $obrigacaoId,
            $novoValorExigivel,
            $totalAlocado,
        ));
    }
}
