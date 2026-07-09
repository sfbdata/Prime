<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Uma linha de alocação de um pagamento (SPEC §11): quanto do pagamento abate UMA obrigação
 * específica. A obrigação é resolvida por id + tenant no AlocadorPagamento (guarda multi-tenant) e
 * tem de pertencer ao MESMO caso do pagamento (invariável 12). Valor em CENTAVOS inteiros.
 */
final class AlocacaoPagamentoInput
{
    #[Assert\NotNull(message: 'Informe a obrigação da alocação.')]
    #[Assert\Positive(message: 'Obrigação inválida.')]
    public ?int $obrigacaoId = null;

    /** Valor alocado a esta obrigação em CENTAVOS inteiros. */
    #[Assert\NotNull(message: 'Informe o valor alocado.')]
    #[Assert\Positive(message: 'O valor alocado deve ser positivo.')]
    public ?int $valor = null;
}
