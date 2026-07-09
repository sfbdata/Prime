<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para gerar uma pendência de Revisão de Pessoa Cobrada (SPEC §8). O gestor aponta o caso a
 * revisar e o motivo (o que mudou no vínculo). O caso é resolvido por id + tenant (guarda
 * multi-tenant) no GerarRevisaoUseCase — aqui só se valida presença e formato.
 */
final class GerarRevisaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Informe o motivo da revisão.')]
    #[Assert\Length(max: 255, maxMessage: 'O motivo deve ter no máximo {{ limit }} caracteres.')]
    public ?string $motivo = null;
}
