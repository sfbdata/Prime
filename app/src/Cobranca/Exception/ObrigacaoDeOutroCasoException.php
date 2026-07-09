<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Uma alocação de pagamento apontou para uma obrigação que NÃO pertence ao mesmo Caso de Cobrança
 * do pagamento. Pagamentos não atravessam casos (SPEC §11, invariável 12) — a alocação é rejeitada.
 */
final class ObrigacaoDeOutroCasoException extends \DomainException
{
    public function __construct(int $obrigacaoId, int $casoId)
    {
        parent::__construct(sprintf(
            'Obrigação %d não pertence ao caso %d do pagamento — pagamento não atravessa casos.',
            $obrigacaoId,
            $casoId,
        ));
    }
}
