<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do registro de um Pagamento num Caso de Cobrança (SPEC §11). O caso é resolvido por
 * id + tenant no RegistrarPagamentoUseCase (guarda multi-tenant). `valorPago` é o BRUTO pago pelo
 * devedor em CENTAVOS inteiros (na forma `acrescido_divida` já embute os honorários — o rateio é do
 * CalculadoraHonorarios). As `alocacoes` distribuem a parte da dívida entre as obrigações do caso
 * (o pagamento não atravessa casos — invariável 12); ao menos uma é obrigatória.
 */
final class RegistrarPagamentoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe a data do pagamento.')]
    public ?\DateTimeImmutable $data = null;

    /** Valor bruto pago pelo devedor em CENTAVOS inteiros. */
    #[Assert\NotNull(message: 'Informe o valor pago.')]
    #[Assert\Positive(message: 'O valor pago deve ser positivo.')]
    public ?int $valorPago = null;

    /**
     * Alocações do pagamento entre as obrigações do caso (invariável 12).
     *
     * @var AlocacaoPagamentoInput[]
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma alocação do pagamento.')]
    public array $alocacoes = [];
}
