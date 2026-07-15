<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de EXCLUIR uma obrigação que já recebeu pagamento (tem alocação de pagamento). Excluí-la
 * apagaria a contrapartida de um pagamento real — inconsistência. Só se exclui obrigação sem rastro
 * financeiro; para desfazer um pagamento, corrija/estorne o pagamento antes.
 */
final class ObrigacaoComPagamentoException extends \DomainException
{
    public function __construct(int $obrigacaoId, int $totalAlocado)
    {
        parent::__construct(sprintf(
            'Obrigação %d tem R$ %s em pagamentos alocados e não pode ser excluída.',
            $obrigacaoId,
            number_format($totalAlocado / 100, 2, ',', '.'),
        ));
    }
}
