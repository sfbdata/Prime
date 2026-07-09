<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Uma parcela de um Acordo (SPEC §12): vira uma nova Obrigação do caso, gerada pelo acordo
 * (`Obrigacao.acordoOrigem`). Descrição obrigatória; valor em CENTAVOS inteiros; vencimento
 * obrigatório. A normalização final da descrição (trim) ocorre no CriarAcordoUseCase.
 */
final class ParcelaAcordoInput
{
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
