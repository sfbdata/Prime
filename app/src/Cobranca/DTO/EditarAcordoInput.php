<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Entrada da EDIÇÃO de um Acordo (Ajuste 7, Fatia 4): reajusta o conjunto de parcelas AINDA NÃO
 * PAGAS de um acordo ATIVO. O acordo é resolvido por id + tenant no UseCase (guarda multi-tenant).
 *
 * `parcelas` é o conjunto COMPLETO do acordo depois da edição — a obrigação da "Entrada" é uma linha
 * como as outras (D8), logo o fechamento aqui é `Σ parcelas == valorTotalNegociado` (sem somar a
 * entrada de novo, diferente da criação). O conjunto de obrigações SUBSTITUÍDAS não é editável
 * (INV-E) e por isso não aparece neste input.
 */
final class EditarAcordoInput
{
    #[Assert\NotNull(message: 'Informe o acordo.')]
    #[Assert\Positive(message: 'Acordo inválido.')]
    public ?int $acordoId = null;

    /** Total negociado em CENTAVOS; quando informado, DEVE fechar com a soma das parcelas (INV-B). */
    #[Assert\PositiveOrZero(message: 'O total negociado não pode ser negativo.')]
    public ?int $valorTotalNegociado = null;

    /**
     * Conjunto completo das parcelas do acordo após a edição.
     *
     * @var ParcelaEdicaoInput[]
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'O acordo precisa de ao menos uma parcela.')]
    public array $parcelas = [];

    /**
     * INV-B na edição: `Σ parcelas == valorTotalNegociado`. Só checa quando o total foi informado —
     * sem total, o acordo passa a valer a soma das parcelas (o UseCase grava o snapshot derivado).
     */
    #[Assert\Callback]
    public function validarFechamentoDoTotal(ExecutionContextInterface $context): void
    {
        if ($this->valorTotalNegociado === null) {
            return;
        }

        $somaParcelas = 0;

        foreach ($this->parcelas as $parcela) {
            $somaParcelas += (int) $parcela->valor;
        }

        if ($somaParcelas !== $this->valorTotalNegociado) {
            $context->buildViolation('A soma das parcelas ({{ soma }}) precisa fechar com o total negociado ({{ total }}).')
                ->setParameter('{{ soma }}', number_format($somaParcelas / 100, 2, ',', '.'))
                ->setParameter('{{ total }}', number_format($this->valorTotalNegociado / 100, 2, ',', '.'))
                ->atPath('parcelas')
                ->addViolation();
        }
    }
}
