<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do lançamento de Obrigação num Caso de Cobrança (SPEC §10). O caso é resolvido por
 * id + tenant no RegistrarObrigacaoUseCase (guarda multi-tenant). O valor é informado em CENTAVOS
 * inteiros e, junto do vencimento, preserva-se como ORIGINAL (invariável 20). A normalização final
 * da referência externa (trim; null se vazio) ocorre no UseCase.
 */
final class RegistrarObrigacaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Informe a descrição da obrigação.')]
    #[Assert\Length(max: 255, maxMessage: 'A descrição pode ter no máximo {{ limit }} caracteres.')]
    public ?string $descricao = null;

    /** Valor original em CENTAVOS inteiros (invariável 20). */
    #[Assert\NotNull(message: 'Informe o valor da obrigação.')]
    #[Assert\Positive(message: 'O valor da obrigação deve ser positivo.')]
    public ?int $valorOriginal = null;

    #[Assert\NotNull(message: 'Informe o vencimento original da obrigação.')]
    public ?\DateTimeImmutable $vencimentoOriginal = null;

    #[Assert\Length(max: 255, maxMessage: 'A referência externa pode ter no máximo {{ limit }} caracteres.')]
    public ?string $referenciaExterna = null;
}
