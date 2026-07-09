<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para concluir a Próxima Ação ativa de um Caso de Cobrança (SPEC §14). O gestor registra o
 * `resultado` da ação e PODE, no mesmo passo, definir a próxima (descrição + prazo opcionais). A ação
 * é resolvida por id + tenant (guarda multi-tenant) no ConcluirAcaoUseCase.
 */
final class ConcluirAcaoInput
{
    #[Assert\NotNull(message: 'Informe a ação a concluir.')]
    #[Assert\Positive(message: 'Ação inválida.')]
    public ?int $acaoId = null;

    #[Assert\NotBlank(message: 'Informe o resultado da ação.')]
    #[Assert\Length(max: 2000, maxMessage: 'O resultado deve ter no máximo {{ limit }} caracteres.')]
    public ?string $resultado = null;

    /** Descrição da próxima ação (opcional): quando preenchida, cria uma nova ação pendente. */
    #[Assert\Length(max: 255, maxMessage: 'A descrição deve ter no máximo {{ limit }} caracteres.')]
    public ?string $proximaDescricao = null;

    /** Prazo da próxima ação; só faz sentido quando `proximaDescricao` é informada. */
    public ?\DateTimeImmutable $proximoPrazo = null;
}
