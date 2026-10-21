<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * A resposta guardada na própria origem, fora da Central (hoje, a da página do Contribua: `Sugestao.resposta`,
 * `respondidaPor` e `respondidaEm`). `por` é nulo quando o Master saiu do BlueJus.
 */
final class RespostaNaOrigem
{
    public function __construct(
        public readonly string $texto,
        public readonly ?User $por,
        public readonly \DateTimeImmutable $em,
    ) {
    }
}
