<?php

declare(strict_types=1);

namespace App\Djen\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de cadastro de OAB monitorada. É a data_class do OabMonitoradaType.
 * A normalização final (só dígitos / UF maiúscula) e a deduplicação ocorrem no UseCase.
 */
final class OabMonitoradaInput
{
    #[Assert\NotBlank(message: 'Informe o número da OAB.')]
    #[Assert\Regex(pattern: '/^\d{1,20}$/', message: 'O número da OAB deve conter apenas dígitos.')]
    public ?string $numero = null;

    #[Assert\NotBlank(message: 'Informe a UF da OAB.')]
    #[Assert\Regex(pattern: '/^[A-Za-z]{2}$/', message: 'A UF deve ter 2 letras.')]
    public ?string $uf = null;

    #[Assert\Length(max: 255, maxMessage: 'O apelido pode ter no máximo {{ limit }} caracteres.')]
    public ?string $apelido = null;
}
