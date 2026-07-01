<?php

declare(strict_types=1);

namespace App\Profile\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de OAB no perfil. Número/UF são opcionais (o usuário pode informar,
 * editar ou limpar). O formato em si é validado pelo ValidadorOab (ponto único — dívida #4),
 * não por Assert, para não duplicar a regra.
 */
final class OabPerfilInput
{
    #[Assert\Length(max: 20)]
    public ?string $oabNumero = null;

    #[Assert\Length(max: 2)]
    public ?string $oabUf = null;
}
