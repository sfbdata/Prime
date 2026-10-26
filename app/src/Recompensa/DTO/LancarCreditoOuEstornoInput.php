<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Crédito ou estorno manual no extrato de um indicador (POST /master/recompensas/usuarios/{id}/lancamento). O valor
 * tem sinal (negativo estorna). A chave de idempotência é o UUID que o navegador gera ao abrir o diálogo.
 */
final readonly class LancarCreditoOuEstornoInput
{
    public function __construct(
        /** Quem lança: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        public int $valorCentavos,
        #[Assert\Length(max: 255, maxMessage: 'A observação deve ter até 255 caracteres.')]
        public ?string $observacao,
        #[Assert\NotBlank(message: 'Pedido sem identificador válido. Feche a janela e abra de novo.')]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9-]{16,64}$/', message: 'Pedido sem identificador válido. Feche a janela e abra de novo.')]
        public string $chaveDeIdempotencia,
    ) {
    }

    /**
     * @param array<mixed> $corpo `{valor ("-10,00"), observacao, chave}`
     *
     * @throws \App\Recompensa\Exception\RecompensaRecusada valor que não é número, zero ou acima do teto
     */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        $observacao = is_string($corpo['observacao'] ?? null) ? trim($corpo['observacao']) : null;

        return new self(
            $ator,
            $userId,
            LeitorDeValoresDeRecompensa::centavosComSinal($corpo['valor'] ?? null),
            $observacao === '' ? null : $observacao,
            is_string($corpo['chave'] ?? null) ? $corpo['chave'] : '',
        );
    }
}
