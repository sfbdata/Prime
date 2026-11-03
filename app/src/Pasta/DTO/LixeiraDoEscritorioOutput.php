<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma página da lixeira do escritório: as pastas com algo na lixeira que quem lê pode restaurar,
 * da exclusão mais recente para a mais antiga. `proximo` é a chave da última pasta conferida
 * ("data|id", a data visível para quem lê): o próximo pedido (`?apos=`) continua depois dela. NULL
 * quando não há mais pastas a conferir.
 */
final class LixeiraDoEscritorioOutput
{
    /**
     * @param list<GrupoDaLixeiraDoEscritorio> $grupos
     */
    public function __construct(
        public readonly array $grupos,
        public readonly ?string $proximo,
    ) {
    }
}
