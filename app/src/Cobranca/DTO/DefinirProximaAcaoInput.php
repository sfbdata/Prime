<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para definir a Próxima Ação operacional de um Caso de Cobrança (SPEC §14). O gestor informa
 * o caso, a descrição da "próxima coisa a fazer" e, opcionalmente, um prazo. O caso é resolvido por
 * id + tenant (guarda multi-tenant) no DefinirProximaAcaoUseCase — aqui só se valida presença/formato.
 */
final class DefinirProximaAcaoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotBlank(message: 'Informe a descrição da próxima ação.')]
    #[Assert\Length(max: 255, maxMessage: 'A descrição deve ter no máximo {{ limit }} caracteres.')]
    public ?string $descricao = null;

    /** Prazo esperado da ação; nulo quando o gestor não define data. */
    public ?\DateTimeImmutable $prazo = null;
}
