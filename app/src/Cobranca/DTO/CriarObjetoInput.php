<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de criação de um Objeto de Cobrança (SPEC §4) dentro de uma carteira
 * escolhida. Será a data_class do formulário de objeto. A resolução da carteira por id + tenant
 * (guarda multi-tenant), a normalização dos textos e a persistência ocorrem no CriarObjetoUseCase —
 * aqui só se validam formato e presença dos campos.
 */
final class CriarObjetoInput
{
    #[Assert\NotNull(message: 'Informe a carteira do objeto.')]
    #[Assert\Positive(message: 'Carteira inválida.')]
    public ?int $carteiraId = null;

    #[Assert\NotBlank(message: 'Informe a identificação do objeto.')]
    #[Assert\Length(max: 255, maxMessage: 'A identificação pode ter no máximo {{ limit }} caracteres.')]
    public ?string $identificacao = null;

    /** Descrição livre (coluna TEXT na entidade) — sem limite de tamanho. */
    public ?string $descricao = null;

    #[Assert\Length(max: 255, maxMessage: 'A referência externa pode ter no máximo {{ limit }} caracteres.')]
    public ?string $referenciaExterna = null;
}
