<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada do registro de um Pagamento num Caso de Cobrança (SPEC §11). O caso é resolvido por
 * id + tenant no RegistrarPagamentoUseCase (guarda multi-tenant). `valorPago` é o BRUTO pago pelo
 * devedor em CENTAVOS inteiros (na forma `acrescido_divida` já embute os honorários — o rateio é do
 * CalculadoraHonorarios). Por padrão a distribuição é AUTOMÁTICA por FIFO (Ajuste 6); só quando
 * `alocarManualmente` as `alocacoes` vêm do usuário (o pagamento não atravessa casos — invariável 12)
 * e ao menos uma é obrigatória.
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

    /** Quando false (padrão), a auto-alocação FIFO preenche as alocações; quando true, vêm do usuário. */
    public bool $alocarManualmente = false;

    /**
     * Alocações do pagamento entre as obrigações do caso (invariável 12). Só usadas em modo manual.
     *
     * @var AlocacaoPagamentoInput[]
     */
    #[Assert\Valid]
    public array $alocacoes = [];

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
