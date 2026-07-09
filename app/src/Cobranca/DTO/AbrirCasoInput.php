<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do formulário de abertura de Caso de Cobrança (SPEC §4/§6). O gestor escolhe o objeto
 * a cobrar e a pessoa cobrada; ambos são resolvidos por id + tenant (guarda multi-tenant) no
 * AbrirCasoUseCase — aqui só se valida presença e formato dos ids.
 */
final class AbrirCasoInput
{
    #[Assert\NotNull(message: 'Informe o objeto de cobrança.')]
    #[Assert\Positive(message: 'Objeto de cobrança inválido.')]
    public ?int $objetoId = null;

    #[Assert\NotNull(message: 'Informe a pessoa cobrada.')]
    #[Assert\Positive(message: 'Pessoa cobrada inválida.')]
    public ?int $pessoaCobradaId = null;
}
