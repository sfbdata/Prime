<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Um movimento (alocação de pagamento ou substituição por acordo) apontou para uma obrigação que
 * NÃO pertence ao mesmo Caso de Cobrança. Pagamentos e acordos não atravessam casos (SPEC §11/§12,
 * invariáveis 12/13) — o movimento é rejeitado.
 */
final class ObrigacaoDeOutroCasoException extends \DomainException
{
    public function __construct(int $obrigacaoId, int $casoId)
    {
        parent::__construct(sprintf(
            'Obrigação %d não pertence ao caso %d — o movimento não atravessa casos.',
            $obrigacaoId,
            $casoId,
        ));
    }
}
