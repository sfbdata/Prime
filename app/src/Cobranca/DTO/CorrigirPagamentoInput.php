<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada da correção de um Pagamento já registrado (SPEC §22). NÃO há estorno no MVP: a correção
 * reescreve a composição do pagamento (valores + alocações) e exige um `motivoCorrecao`, tudo
 * rastreável pela auditoria técnica. O pagamento é resolvido por id + tenant no
 * CorrigirPagamentoUseCase (guarda multi-tenant). `data` é OPCIONAL: só sobrescreve a data original
 * quando informada. `valorPago` é o BRUTO pago em CENTAVOS inteiros. Por padrão a nova distribuição é
 * AUTOMÁTICA por FIFO (Ajuste 6); só em `alocarManualmente` as `alocacoes` vêm do usuário.
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

    /** Quando false (padrão), a auto-alocação FIFO preenche as alocações; quando true, vêm do usuário. */
    public bool $alocarManualmente = false;

    /**
     * Nova distribuição do pagamento entre as obrigações do caso (invariável 12). Só usadas em modo manual.
     *
     * @var AlocacaoPagamentoInput[]
     */
    #[Assert\Valid]
    public array $alocacoes = [];

    // normalizer 'trim': rejeita motivo só com espaços (o UseCase também aplica trim antes de gravar).
    #[Assert\NotBlank(message: 'Informe o motivo da correção.', normalizer: 'trim')]
    public ?string $motivoCorrecao = null;

    /** Em modo manual, exige ao menos uma alocação (no automático o FIFO gera). */
    #[Assert\Callback]
    public function validarAlocacoesManuais(ExecutionContextInterface $context): void
    {
        if ($this->alocarManualmente && $this->alocacoes === []) {
            $context->buildViolation('Informe ao menos uma alocação do pagamento.')
                ->atPath('alocacoes')
                ->addViolation();
        }
    }
}
