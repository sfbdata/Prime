<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Condição individual de um indicador (POST /master/recompensas/usuarios/{id}/condicao). Valor, "libera com" e carência
 * vazios (nulos) usam a regra geral; recorrente exige o valor mensal. Reais em texto viram centavos em `doJson()`.
 */
final readonly class DefinirCondicaoIndividualInput
{
    public function __construct(
        /** Quem altera: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        #[Assert\Range(min: 0, max: 10000000, notInRangeMessage: 'A recompensa por escritório deve ficar entre R$ 0,00 e R$ 100.000,00.')]
        public ?int $valorCentavos,
        #[Assert\Range(min: 1, max: 100000, notInRangeMessage: 'O número de escritórios ativos para liberar deve ser de 1 a 100000.')]
        public ?int $minEscritorios,
        #[Assert\Range(min: 0, max: 3650, notInRangeMessage: 'A carência deve ser de 0 a 3650 dias.')]
        public ?int $carenciaDias,
        public bool $recorrente,
        #[Assert\Range(min: 0, max: 10000000, notInRangeMessage: 'O valor mensal deve ficar entre R$ 0,00 e R$ 100.000,00.')]
        public ?int $mensalCentavos,
        #[Assert\Length(max: 255, maxMessage: 'O motivo deve ter até 255 caracteres.')]
        public ?string $motivo,
    ) {
    }

    /**
     * @param array<mixed> $corpo `{valor, minEscritorios, carenciaDias, recorrente, mensal, motivo}`
     *
     * @throws \App\Recompensa\Exception\RecompensaRecusada número que não é número
     */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        return new self(
            $ator,
            $userId,
            LeitorDeValoresDeRecompensa::centavos($corpo['valor'] ?? null),
            LeitorDeValoresDeRecompensa::inteiro($corpo['minEscritorios'] ?? null),
            LeitorDeValoresDeRecompensa::inteiro($corpo['carenciaDias'] ?? null),
            ($corpo['recorrente'] ?? false) === true,
            LeitorDeValoresDeRecompensa::centavos($corpo['mensal'] ?? null),
            is_string($corpo['motivo'] ?? null) ? trim($corpo['motivo']) : null,
        );
    }
}
