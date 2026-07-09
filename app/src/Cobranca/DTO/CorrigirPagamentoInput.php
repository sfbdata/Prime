<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da correção de um Pagamento já registrado (SPEC §22). NÃO há estorno no MVP: a correção
 * reescreve a composição do pagamento (valores + alocações) e exige um `motivoCorrecao`, tudo
 * rastreável pela auditoria técnica. O pagamento é resolvido por id + tenant no
 * CorrigirPagamentoUseCase (guarda multi-tenant). `data` é OPCIONAL: só sobrescreve a data original
 * quando informada. `valorPago` é o BRUTO pago em CENTAVOS inteiros.
 */
final class CorrigirPagamentoInput
{
    #[Assert\NotNull(message: 'Informe o pagamento a corrigir.')]
    #[Assert\Positive(message: 'Pagamento inválido.')]
    public ?int $pagamentoId = null;

    /** Data opcional: quando informada, sobrescreve a data do pagamento. */
    public ?\DateTimeImmutable $data = null;

    /** Valor bruto pago pelo devedor em CENTAVOS inteiros. */
    #[Assert\NotNull(message: 'Informe o valor pago.')]
    #[Assert\Positive(message: 'O valor pago deve ser positivo.')]
    public ?int $valorPago = null;

    /**
     * Nova distribuição do pagamento entre as obrigações do caso (invariável 12).
     *
     * @var AlocacaoPagamentoInput[]
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Informe ao menos uma alocação do pagamento.')]
    public array $alocacoes = [];

    #[Assert\NotBlank(message: 'Informe o motivo da correção.')]
    public ?string $motivoCorrecao = null;
}
