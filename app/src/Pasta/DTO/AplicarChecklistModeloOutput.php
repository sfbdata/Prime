<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O resultado de aplicar um modelo numa pasta: o que entrou e o que já estava lá.
 *
 * Os ignorados são contados, não escondidos. Aplicar um modelo de 8 itens numa pasta que
 * já tinha 3 deles adiciona 5 — e quem clicou precisa ver esse número, senão fica sem
 * saber se o botão funcionou.
 */
final readonly class AplicarChecklistModeloOutput
{
    /**
     * @param list<array{id: int, titulo: string}> $criados itens novos, na ordem em que entraram
     * @param string[]                             $ignorados títulos que a pasta já tinha
     */
    public function __construct(
        public array $criados,
        public array $ignorados,
    ) {}

    public function totalCriados(): int
    {
        return count($this->criados);
    }

    public function totalIgnorados(): int
    {
        return count($this->ignorados);
    }
}
