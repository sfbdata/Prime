<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do encerramento MANUAL de um Caso de Cobrança (SPEC §17). O gestor confirma o
 * encerramento do caso (resolvido por id + tenant no EncerrarCasoUseCase) e pode registrar uma
 * observação livre na linha do tempo. O encerramento só é permitido com saldo exigível resolvido.
 */
final class EncerrarCasoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\Length(max: 255, maxMessage: 'A observação deve ter no máximo {{ limit }} caracteres.')]
    public ?string $observacao = null;
}
