<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Uma linha do editor de parcelas do Acordo (Ajuste 7, Fatia 4).
 *
 * `obrigacaoId` null = parcela NOVA (será criada); preenchido = parcela existente (será atualizada,
 * e deve pertencer a este acordo — o UseCase valida). Parcela existente que NÃO vier na coleção é
 * removida. Valor em CENTAVOS. A linha da "Entrada" é editada como qualquer outra (D8).
 */
final class ParcelaEdicaoInput
{
    #[Assert\Positive(message: 'Parcela inválida.')]
    public ?int $obrigacaoId = null;

    #[Assert\NotBlank(message: 'Informe a descrição da parcela.')]
    #[Assert\Length(max: 255, maxMessage: 'A descrição pode ter no máximo {{ limit }} caracteres.')]
    public ?string $descricao = null;

    /** Valor da parcela em CENTAVOS inteiros. */
    #[Assert\NotNull(message: 'Informe o valor da parcela.')]
    #[Assert\Positive(message: 'O valor da parcela deve ser positivo.')]
    public ?int $valor = null;

    #[Assert\NotNull(message: 'Informe o vencimento da parcela.')]
    public ?\DateTimeImmutable $vencimento = null;
}
