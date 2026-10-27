<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Recompensa\Entity\CondicaoDeRecompensa;

/** A condição individual como a tela a edita: campo nulo é "regra geral". */
final readonly class CondicaoIndividualOutput
{
    public function __construct(
        public ?int $valorCentavos,
        public ?int $minEscritorios,
        public ?int $carenciaDias,
        public bool $recorrente,
        public ?int $mensalCentavos,
        public ?string $motivo,
    ) {
    }

    public static function da(CondicaoDeRecompensa $c): self
    {
        return new self($c->getValorCentavos(), $c->getMinEscritorios(), $c->getCarenciaDias(), $c->isRecorrente(), $c->getMensalCentavos(), $c->getMotivo());
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'valorCentavos' => $this->valorCentavos, 'minEscritorios' => $this->minEscritorios, 'carenciaDias' => $this->carenciaDias,
            'recorrente' => $this->recorrente, 'mensalCentavos' => $this->mensalCentavos, 'motivo' => $this->motivo,
        ];
    }
}
