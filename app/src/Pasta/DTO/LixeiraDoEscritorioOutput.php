<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma página da lixeira do escritório: as pastas com algo na lixeira que quem lê pode restaurar,
 * da exclusão mais recente para a mais antiga. `proximo` é de onde o próximo pedido continua
 * (`?apos=`), ou NULL quando não há mais pastas a conferir.
 */
final class LixeiraDoEscritorioOutput
{
    /**
     * @param list<GrupoDaLixeiraDoEscritorio> $grupos
     */
    public function __construct(
        public readonly array $grupos,
        public readonly ?int $proximo,
    ) {
    }
}
