<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de editar/excluir DIRETAMENTE uma obrigação travada por um acordo VIGENTE — parcela de acordo
 * ativo/cumprido, ou original substituída por acordo ativo/cumprido. Essas são geridas PELO acordo
 * (rompê-lo/cancelá-lo ou, futuramente, editá-lo — item 7), nunca por fora, para não corromper a
 * negociação (invariáveis 14/15). Acordo rompido/cancelado não trava (a original volta, a parcela vira
 * histórico).
 */
final class ObrigacaoDeAcordoException extends \DomainException
{
    public function __construct(int $obrigacaoId)
    {
        parent::__construct(sprintf(
            'Obrigação %d participa de um acordo vigente e deve ser gerenciada pelo acordo.',
            $obrigacaoId,
        ));
    }
}
