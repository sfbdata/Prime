<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de fazer um acordo NOVO substituir uma obrigação que é PARCELA de outro acordo — "acordo
 * sobre acordo" (ajuste 9, INV-I). Um acordo só substitui DÍVIDA ORIGINAL.
 *
 * Distinta de `ObrigacaoDeAcordoException`, que fala de obrigação travada por acordo VIGENTE (edição/
 * exclusão direta): aqui a recusa vale para parcela de acordo em QUALQUER status — a de acordo vigente
 * porque duplicaria a dívida no saldo ao romper o acordo de origem (§2.1), a de acordo rompido/cancelado
 * porque é histórico e nem sequer é exigível.
 */
final class ObrigacaoNaoEhDividaOriginalException extends \DomainException
{
    public function __construct(int $obrigacaoId)
    {
        parent::__construct(sprintf(
            'Obrigação %d é parcela de um acordo e não pode ser renegociada por um acordo novo. '
            . 'Para refazer um acordo, rompa o acordo atual primeiro.',
            $obrigacaoId,
        ));
    }
}
