<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Revisar suspeitas e aprovar": o Master aprova as contas suspeitas pendentes de um indicador. Leva a quantidade que ele
 * viu no diálogo (`vistas`); se a lista mudou no servidor, a aprovação é recusada e ele revisa de novo.
 */
final readonly class AprovarSuspeitasDoIndicadorInput
{
    public function __construct(
        /** Quem aprova: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        #[Assert\GreaterThanOrEqual(value: 1, message: 'Informe a quantidade de suspeitas revisadas.')]
        public int $vistas,
    ) {
    }

    /** @param array<mixed> $corpo `{vistas}`; o que não for inteiro vira 0 e é recusado pela validação */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        return new self($ator, $userId, is_int($corpo['vistas'] ?? null) ? $corpo['vistas'] : 0);
    }
}
